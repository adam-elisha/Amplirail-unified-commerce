<?php
declare(strict_types=1);
namespace AmpliRail\Ledger;

use AmpliRail\Money\Money;

/**
 * Append-only double-entry ledger (rule 4). Every journal sums to zero per currency.
 * Production: LedgerRepository writes to partitioned ledger_postings in the same DB transaction as the outbox event.
 */
final class Ledger
{
    /** @var array<int,array{journal:string,account:string,amount:Money,source:string}> */
    private array $postings = [];

    /** @param array<int,array{0:string,1:Money}> $lines  [account, signed amount] debit positive, credit negative */
    public function post(string $journalId, string $source, array $lines): void
    {
        $sum = [];
        foreach ($lines as [$acct, $m]) { $sum[$m->currency] = ($sum[$m->currency] ?? 0) + $m->minor; }
        foreach ($sum as $cur => $v) { if ($v !== 0) throw new \LogicException("Unbalanced journal $journalId in $cur: $v"); }
        foreach ($lines as [$acct, $m]) { $this->postings[] = ['journal' => $journalId, 'account' => $acct, 'amount' => $m, 'source' => $source]; }
    }

    public function balance(string $account, string $currency): Money
    {
        $t = 0;
        foreach ($this->postings as $p) { if ($p['account'] === $account && $p['amount']->currency === $currency) $t += $p['amount']->minor; }
        return Money::of($t, $currency);
    }

    public function isBalanced(): bool
    {
        $s = [];
        foreach ($this->postings as $p) { $s[$p['amount']->currency] = ($s[$p['amount']->currency] ?? 0) + $p['amount']->minor; }
        foreach ($s as $v) if ($v !== 0) return false;
        return true;
    }

    public function count(): int { return count($this->postings); }
}
