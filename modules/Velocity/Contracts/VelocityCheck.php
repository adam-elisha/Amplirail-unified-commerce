<?php

declare(strict_types=1);

namespace Modules\Velocity\Contracts;

interface VelocityCheck
{
    /** Checks portfolio, merchant, BIN and card brand rules and counts the attempt. Returns the blocking rule name or null. */
    public function check(VelocityInput $in): ?string;

    /** Checks processor (connector) rules for one route and counts the attempt on that route when allowed. */
    public function allowsConnector(VelocityInput $in, string $connector): bool;
}
