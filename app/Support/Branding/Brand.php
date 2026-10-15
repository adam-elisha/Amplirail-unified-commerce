<?php

declare(strict_types=1);

namespace App\Support\Branding;

/** What the portals, sign-in pages, pay pages and footers show for the current host. */
final class Brand
{
    public function __construct(
        public readonly ?string $tenantId,
        public readonly string $name,
        public readonly string $company,
        public readonly string $mark,
        public readonly string $tagline,
        public readonly string $primaryColor,
        public readonly string $navyColor,
        public readonly ?string $logoData,
        public readonly ?string $supportEmail,
        public readonly ?string $supportPhone,
        public readonly string $termsUrl,
        public readonly string $privacyUrl,
        public readonly string $securityUrl,
    ) {}

    public static function platform(): self
    {
        return new self(
            null,
            'AmpliRail',
            'AmpliPay Group Inc.',
            'A',
            'One rail for every processor, channel and partner.',
            '#1A5CFF',
            '#0B1F4B',
            null,
            null,
            null,
            (string) config('amplirail.legal.terms', '/terms'),
            (string) config('amplirail.legal.privacy', '/privacy'),
            (string) config('amplirail.legal.security', '/security'),
        );
    }

    public static function fromRecord(BrandRecord $r): self
    {
        $p = self::platform();

        return new self(
            $r->tenant_id,
            $r->name,
            $r->company,
            $r->mark,
            $r->tagline ?? $p->tagline,
            self::color($r->primary_color, $p->primaryColor),
            self::color($r->navy_color, $p->navyColor),
            $r->logo_data,
            $r->support_email,
            $r->support_phone,
            $r->terms_url ?: $p->termsUrl,
            $r->privacy_url ?: $p->privacyUrl,
            $r->security_url ?: $p->securityUrl,
        );
    }

    public function isPartner(): bool
    {
        return $this->tenantId !== null;
    }

    /** A slightly darker shade of the primary colour for hover states. */
    public function primaryDark(): string
    {
        $rgb = array_map(fn (string $h): int => (int) max(0, hexdec($h) - 24), str_split(ltrim($this->primaryColor, '#'), 2));

        return sprintf('#%02X%02X%02X', ...$rgb);
    }

    public static function color(?string $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : $fallback;
    }
}
