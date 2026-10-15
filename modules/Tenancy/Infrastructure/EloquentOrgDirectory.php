<?php

declare(strict_types=1);

namespace Modules\Tenancy\Infrastructure;

use Modules\Tenancy\Contracts\OrgDirectory;

final class EloquentOrgDirectory implements OrgDirectory
{
    public function ensureMerchantNode(string $tenantId, string $merchantId, string $name): string
    {
        $root = OrgNode::query()->whereKey($tenantId)->where('type', 'organization')->firstOrFail();
        $node = OrgNode::query()->firstOrCreate(
            ['id' => $merchantId],
            [
                'type' => 'merchant',
                'parent_id' => $root->id,
                'tenant_id' => $tenantId,
                'path' => $root->path.$merchantId.'/',
                'name' => $name,
                'status' => 'active',
            ],
        );

        return $node->path;
    }

    public function tenantIds(): array
    {
        /** @var list<string> */
        return OrgNode::query()->where('type', 'organization')->where('status', 'active')->orderBy('id')->pluck('id')->all();
    }

    public function tenantExists(string $tenantId): bool
    {
        return OrgNode::query()->whereKey($tenantId)->where('type', 'organization')->exists();
    }
}
