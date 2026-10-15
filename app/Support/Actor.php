<?php

declare(strict_types=1);

namespace App\Support;

/** Who caused a change. Carried on every event envelope and audit row (master document 11). */
final class Actor
{
    public function __construct(
        public readonly string $type,   // user | system | service | api_key
        public readonly string $id,
        public readonly ?string $name = null,
    ) {}

    public static function system(string $id = 'system'): self
    {
        return new self('system', $id, 'AmpliRail');
    }

    /** @return array{type:string,id:string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
