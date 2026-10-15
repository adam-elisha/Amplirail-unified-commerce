<?php

declare(strict_types=1);

use AmpliRail\Money\Money;

if (! function_exists('money')) {
    /** Display helper for views: integer minor units -> "1,234.56 USD" (core Money formatting, no floats). */
    function money(?int $minor, ?string $currency): string
    {
        return $minor === null || $currency === null ? '-' : Money::of($minor, $currency)->format();
    }
}
