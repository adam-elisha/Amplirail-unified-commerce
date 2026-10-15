<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Identity\Contracts\AccessControl;
use Modules\Identity\Contracts\StepUpVerifier;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Infrastructure\Invitation;
use Modules\Identity\Infrastructure\Membership;
use Modules\Identity\Infrastructure\User;
use Modules\Identity\Mail\InvitationMail;

/** User invitations, memberships, access checks and step-up verification. */
final class UserAdministration implements AccessControl, StepUpVerifier, UserDirectory
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly AuthenticatorCodes $totp,
        private readonly EmailOtp $emailOtp,
    ) {}

    /** @return array{user: User, token: string} */
    public function invite(string $tenantId, string $orgNodeId, string $orgPath, ?string $merchantId, string $email, string $name, string $role): array
    {
        $portal = (string) config("amplirail_roles.roles.$role.portal");
        if ($portal === '') {
            throw new \InvalidArgumentException("Unknown role $role");
        }
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($tenantId, $orgNodeId, $orgPath, $merchantId, $email, $name, $role, $portal): array {
            $user = User::query()->where('email', $email)->first();
            if ($user !== null && $user->portal !== $portal) {
                throw new \DomainException('This email already belongs to a user of another portal.');
            }
            $user ??= User::query()->create([
                'id' => Ids::new('usr'), 'name' => $name, 'email' => $email, 'portal' => $portal,
                'status' => 'invited', 'failed_attempts' => 0,
            ]);
            Membership::query()->firstOrCreate(
                ['user_id' => $user->id, 'tenant_id' => $tenantId, 'merchant_id' => $merchantId, 'role' => $role],
                ['id' => Ids::new('mbr'), 'org_node_id' => $orgNodeId, 'org_path' => $orgPath, 'status' => 'active'],
            );
            $token = Str::random(64);
            Invitation::query()->create([
                'id' => Ids::new('inv'), 'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours((int) config('amplirail.auth.invitation_ttl_hours')),
            ]);
            $this->audit->record('user.invited', 'user', $user->id, null, ['role' => $role, 'merchant_id' => $merchantId]);
            $url = rtrim((string) config('amplirail.public_urls.'.($portal === 'admin' ? 'admin' : 'merchant')), '/')
                .($portal === 'admin' ? '/admin' : '/app').'/invitation/'.$token;
            Mail::to($email)->queue(new InvitationMail($name, $url, $portal));

            return ['user' => $user, 'token' => $token];
        });
    }

    public function inviteMerchantOwner(string $tenantId, string $merchantId, string $orgPath, string $email, string $name): string
    {
        $existing = User::query()->where('email', mb_strtolower(trim($email)))->first();
        if ($existing !== null && Membership::query()->where('user_id', $existing->id)->where('merchant_id', $merchantId)->exists()) {
            return $existing->id;
        }

        return $this->invite($tenantId, $merchantId, $orgPath, $merchantId, $email, $name, 'merchant_owner')['user']->id;
    }

    public function acceptInvitation(string $token, string $password): ?User
    {
        return DB::transaction(function () use ($token, $password): ?User {
            $inv = Invitation::query()->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->lockForUpdate()->first();
            if ($inv === null || $inv->expires_at->isPast()) {
                return null;
            }
            $inv->forceFill(['accepted_at' => now()])->save();
            /** @var User $user */
            $user = User::query()->findOrFail($inv->user_id);
            $user->forceFill(['password' => $password, 'status' => 'active'])->save();
            $this->audit->record('user.activated', 'user', $user->id, null, null);

            return $user;
        });
    }

    public function findInvitation(string $token): ?Invitation
    {
        $inv = Invitation::query()->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->first();

        return $inv !== null && $inv->expires_at->isFuture() ? $inv : null;
    }

    public function userCan(string $userId, string $permission, string $tenantId): bool
    {
        $user = User::query()->find($userId);
        if ($user === null || $user->status !== 'active') {
            return false;
        }

        return Membership::query()->where('user_id', $userId)->where('tenant_id', $tenantId)->where('status', 'active')->get()
            ->contains(fn (Membership $m) => in_array($permission, $m->permissions(), true));
    }

    public function isPlatformOwner(string $userId, string $tenantId): bool
    {
        return $this->userCan($userId, 'platform.emergency_override', $tenantId);
    }

    public function displayName(string $userId): string
    {
        return User::query()->find($userId)->name ?? $userId;
    }

    public function verify(string $userId, string $code): bool
    {
        $user = User::query()->find($userId);
        if ($user === null || $user->isLocked()) {
            return false;
        }

        // Admins with an enrolled authenticator use it; admins without one (authenticator step turned off) use an emailed code.
        return $user->portal === 'admin' && $user->totp_confirmed_at !== null
            ? $this->totp->verify($user, $code, 'step_up')
            : $this->emailOtp->verify($user, $code, 'step_up');
    }
}
