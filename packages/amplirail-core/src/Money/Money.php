<?php
declare(strict_types=1);
namespace AmpliRail\Money;

/** Integer minor units + currency code. Never floats (rule 3). */
final class Money
{
    public function __construct(public readonly int $minor, public readonly string $currency)
    {
        if (!preg_match('/^[A-Z]{3,5}$/', $currency)) {
            throw new \InvalidArgumentException("Invalid currency code: $currency");
        }
    }

    public static function of(int $minor, string $currency): self { return new self($minor, $currency); }

    public function plus(Money $o): self { $this->same($o); return new self($this->minor + $o->minor, $this->currency); }
    public function minus(Money $o): self { $this->same($o); return new self($this->minor - $o->minor, $this->currency); }
    public function negate(): self { return new self(-$this->minor, $this->currency); }

    /** Percentage in basis points (850 = 8.50%), rounded half-up at the minor unit. */
    public function percentBps(int $bps): self
    {
        return new self(self::roundHalfUpDiv($this->minor * $bps, 10000), $this->currency);
    }

    public function times(int $count): self { return new self($this->minor * $count, $this->currency); }

    public static function roundHalfUpDiv(int $num, int $den): int
    {
        $sign = ($num < 0) ? -1 : 1;
        $q = intdiv(abs($num) * 2 + $den, 2 * $den);
        return $sign * $q;
    }

    private function same(Money $o): void
    {
        if ($o->currency !== $this->currency) {
            throw new \LogicException("Currency mismatch {$this->currency} vs {$o->currency}: convert explicitly first");
        }
    }

    public function format(int $decimals = 2): string
    {
        $neg = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);
        if ($decimals === 0) return $neg . number_format($abs) . ' ' . $this->currency;
        $pow = 10 ** $decimals;
        return $neg . number_format(intdiv($abs, $pow)) . '.' . str_pad((string)($abs % $pow), $decimals, '0', STR_PAD_LEFT) . ' ' . $this->currency;
    }
}
