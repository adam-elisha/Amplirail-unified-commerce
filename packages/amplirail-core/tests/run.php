<?php
declare(strict_types=1);
// Minimal runner so tests work without Composer. In the repo, port these to Pest/PHPUnit (same assertions).
spl_autoload_register(function ($c) { $p = __DIR__ . '/../src/' . str_replace(['AmpliRail\\', '\\'], ['', '/'], $c) . '.php'; if (is_file($p)) require $p; });

use AmpliRail\Money\Money;
use AmpliRail\Payouts\{FeeSchedule, StatementInput, PayoutStatementCalculator};
use AmpliRail\Connectors\Contracts\{PaymentRequest, ConnectorResult};
use AmpliRail\Connectors\Simulator\SimulatorConnector;
use AmpliRail\Connectors\Nmi\NmiBridgeConnector;
use AmpliRail\Http\HttpClient;

$fail = 0; $n = 0;
function check(string $name, bool $ok) { global $fail, $n; $n++; echo ($ok ? "PASS " : "FAIL ") . $name . PHP_EOL; if (!$ok) $fail++; }

// Money
check('round half up 11768.675 -> 11769', Money::of(138455, 'USD')->percentBps(850)->minor === 11769);
check('round half up 6922.75 -> 6923', Money::of(138455, 'USD')->percentBps(500)->minor === 6923);
try { Money::of(100, 'USD')->plus(Money::of(100, 'EUR')); check('currency mix blocked', false); } catch (\LogicException $e) { check('currency mix blocked', true); }

// Payout statement: Test Merchant 01, first week of month, ACH
$s = FeeSchedule::fromArray(json_decode(file_get_contents(__DIR__ . '/../config/test_merchant_fee_schedule.json'), true));
$calc = new PayoutStatementCalculator();
$in = new StatementInput('Test Merchant 01', 'TM-0001', '2026-09-21', '2026-09-27', 11, 138455, 20, 0, 0, 0, 0, 0, 0, true, 'ach');
$r = $calc->calculate($in, $s);
check('fees 159.24', $r['fees']->minor === 15924);
check('reserve 69.23', $r['reserve']->minor === 6923);
check('net 1,156.08 (lines add up)', $r['net']->minor === 115608);
$sum = $r['gross']->minor; foreach ($r['lines'] as $l) $sum += $l['amount']->minor;
check('gross plus lines equals net', $sum === $r['net']->minor);

// Monthly fee only on first statement of month
$in2 = new StatementInput('Test Merchant 01', 'TM-0001', '2026-09-28', '2026-10-04', 11, 138455, 20, 0, 0, 0, 0, 0, 0, false, 'ach');
check('no monthly fee on later weeks', $calc->calculate($in2, $s)['fees']->minor === 14924);

// Chargeback ratio uses settled sales, tier switches above 1%
$in3 = new StatementInput('Test Merchant 01', 'TM-0001', '2026-09-21', '2026-09-27', 50, 500000, 400, 0, 0, 1, 4900, 0, 0, false, 'ach');
$r3 = $calc->calculate($in3, $s);
check('ratio 1 of 50 = 200 bps (declines ignored)', $r3['cb_ratio_bps'] === 200);
$cbLine = array_values(array_filter($r3['lines'], fn($l) => $l['label'] === 'Chargeback fee'))[0]['amount']->minor;
check('high-ratio chargeback fee 75.00', $cbLine === -7500);

// USDT payout fee on net before fee
$in4 = new StatementInput('Test Merchant 01', 'TM-0001', '2026-09-21', '2026-09-27', 11, 138455, 20, 0, 0, 0, 0, 0, 0, true, 'usdt');
$r4 = $calc->calculate($in4, $s);
check('USDT 1.5% of 1,156.08 = 17.34', $r4['net']->minor === 115608 - 1734);

// Simulator scenarios
$sim = new SimulatorConnector();
$mk = fn(int $minor, string $tok = 'tok_sim_approved') => new PaymentRequest('idem-' . $minor, 'mid-1', Money::of($minor, 'USD'), $tok, 'ord-' . $minor);
check('sim approve', $sim->sale($mk(1000))->approved());
check('sim hard decline .02', $sim->sale($mk(1002))->responseCode === '05');
check('sim soft decline .51', $sim->sale($mk(1051))->softDecline === true);
check('sim timeout .99 retryable', $sim->sale($mk(1099))->retryableError === true);
try { $mk(1000, '4111111111111111'); check('card number rejected as token', false); } catch (\InvalidArgumentException $e) { check('card number rejected as token', true); }

// NMI bridge mapping with a fake HTTP client (no network)
$fake = new class implements HttpClient { public array $last = []; public function post(string $url, array $h, string $body, int $t): array { parse_str($body, $this->last); return ['status' => 200, 'body' => 'response=1&responsetext=SUCCESS&authcode=123456&transactionid=999&response_code=100']; } };
$nmi = new NmiBridgeConnector($fake, 'https://provider.example/transact', fn($mid) => 'test-key-from-secrets');
$res = $nmi->sale($mk(1000));
check('nmi approved mapped', $res->approved() && $res->providerTransactionId === '999');
check('nmi sends token not card', ($fake->last['payment_token'] ?? '') === 'tok_sim_approved' && !isset($fake->last['ccnumber']));
check('nmi amount decimal', ($fake->last['amount'] ?? '') === '10.00');
check('security key not in raw result', !isset($res->raw['security_key']));


// ---------- Launch slice: orchestration
use AmpliRail\Ledger\Ledger;
use AmpliRail\Events\Outbox;
use AmpliRail\Payments\{IdempotencyStore, TransactionService};
use AmpliRail\Routing\{Router, MerchantAccount};
use AmpliRail\Screening\Screening;
use AmpliRail\Support\FixedClock;
use AmpliRail\Webhooks\WebhookSigner;
use AmpliRail\Links\PaymentLinks;
use AmpliRail\Payouts\StatementExporter;
use AmpliRail\Connectors\Contracts\{PaymentConnector};

$clock = new FixedClock(new DateTimeImmutable('2026-10-05 12:00:00', new DateTimeZone('UTC')));
$down = new class implements PaymentConnector { public int $calls = 0;
  public function code(): string { return 'down'; }
  private function e(): ConnectorResult { $this->calls++; return new ConnectorResult(ConnectorResult::ERROR, null, null, 'timeout', 'Timeout', retryableError: true); }
  public function sale(PaymentRequest $r): ConnectorResult { return $this->e(); } public function authorize(PaymentRequest $r): ConnectorResult { return $this->e(); }
  public function capture(PaymentRequest $r): ConnectorResult { return $this->e(); } public function refund(PaymentRequest $r): ConnectorResult { return $this->e(); } public function void(PaymentRequest $r): ConnectorResult { return $this->e(); } };
$accounts = [
  new MerchantAccount('mid_us_primary', 'm_test01', 'down', 'USD', 1),
  new MerchantAccount('mid_us_backup', 'm_test01', 'simulator', 'USD', 2),
  new MerchantAccount('mid_eu', 'm_test01', 'simulator', 'EUR', 1),
  new MerchantAccount('mid_other_business', 'm_other', 'simulator', 'USD', 0),
];
$ledger = new Ledger(); $outbox = new Outbox(); $idem = new IdempotencyStore();
$svc = new TransactionService(['simulator' => new SimulatorConnector(), 'down' => $down], $accounts, new Router(), new Screening($clock), $idem, $ledger, $outbox);
$rules = ['max_ticket_minor' => 500000, 'max_per_token_per_hour' => 5, 'blocked_tokens' => ['tok_blocked']];

$r1 = $svc->sale('t1', 'm_test01', 'k1', Money::of(2500, 'USD'), 'tok_sim_approved', 'ord-1', $rules);
check('sale approved after failover from timeout', $r1['status'] === 'approved' && $r1['attempts'] === 2);
check('routed to merchant\'s own backup MID only', $svc->transactions[$r1['transaction_id']]['mid'] === 'mid_us_backup');
$r1b = $svc->sale('t1', 'm_test01', 'k1', Money::of(2500, 'USD'), 'tok_sim_approved', 'ord-1', $rules);
check('idempotent replay returns same transaction', $r1b['transaction_id'] === $r1['transaction_id'] && count($svc->transactions) === 1);
$r1c = $svc->sale('t1', 'm_test01', 'k1', Money::of(9999, 'USD'), 'tok_sim_approved', 'ord-1', $rules);
check('same key different request rejected', $r1c['status'] === 'rejected');
check('ledger balanced after sale', $ledger->isBalanced() && $ledger->balance('merchant_payable:m_test01', 'USD')->minor === -2500);

$r2 = $svc->sale('t1', 'm_test01', 'k2', Money::of(1002, 'USD'), 'tok_sim_approved', 'ord-2', $rules);
check('hard decline not rerouted', $r2['status'] === 'declined' && $r2['response_code'] === '05' && $r2['attempts'] === 2);
$r3 = $svc->sale('t1', 'm_test01', 'k3', Money::of(1000, 'EUR'), 'tok_sim_approved', 'ord-3', $rules);
check('EUR routed to EUR MID', $r3['status'] === 'approved' && $svc->transactions[$r3['transaction_id']]['mid'] === 'mid_eu');
$r4 = $svc->sale('t1', 'm_test01', 'k4', Money::of(1000, 'GBP'), 'tok_sim_approved', 'ord-4', $rules);
check('no eligible MID for GBP', $r4['status'] === 'failed' && $r4['reason'] === 'no_eligible_mid');
$r5 = $svc->sale('t1', 'm_test01', 'k5', Money::of(1000, 'USD'), 'tok_blocked', 'ord-5', $rules);
check('blocked token stopped by screening', $r5['status'] === 'blocked');
$r6 = $svc->sale('t1', 'm_test01', 'k6', Money::of(900000, 'USD'), 'tok_sim_approved', 'ord-6', $rules);
check('over max ticket stopped', $r6['reason'] === 'over_max_ticket');
for ($i = 0; $i < 6; $i++) $last = $svc->sale('t1', 'm_test01', 'kv' . $i, Money::of(1000 + 0, 'USD'), 'tok_velocity', 'ordv' . $i, $rules);
check('velocity limit per token per hour', $last['status'] === 'blocked' && $last['reason'] === 'velocity');

$ref = $svc->refund('t1', $r1['transaction_id'], Money::of(1000, 'USD'), 'rk1');
check('partial refund ok', $ref['status'] === 'refunded' && $ref['refunded_minor'] === 1000);
$ref2 = $svc->refund('t1', $r1['transaction_id'], Money::of(2000, 'USD'), 'rk2');
check('refund over remaining rejected', $ref2['reason'] === 'over_refundable_amount');
check('ledger balanced after refund', $ledger->isBalanced() && $ledger->balance('merchant_payable:m_test01', 'USD')->minor === -1500);
check('events emitted', in_array('transaction.approved', $outbox->types(), true) && in_array('refund.succeeded', $outbox->types(), true));
check('outbox relay publishes each event once', $outbox->relay(fn($e) => null) === count($outbox->events) && $outbox->relay(fn($e) => null) === 0);

// ---------- Webhooks
$body = '{"type":"transaction.approved"}'; $ts = 1791000000;
$h = WebhookSigner::sign('arwhk_sbx_test', $body, $ts);
check('webhook signature verifies', WebhookSigner::verify('arwhk_sbx_test', $body, $h, $ts + 10));
check('tampered webhook rejected', !WebhookSigner::verify('arwhk_sbx_test', $body . ' ', $h, $ts + 10));
check('old webhook rejected', !WebhookSigner::verify('arwhk_sbx_test', $body, $h, $ts + 1000));

// ---------- Payment links
$pl = new PaymentLinks('link-signing-key-from-secrets');
$tok = $pl->create('m_test01', 15000, 'USD', 'INV-1001', $ts + 86400);
check('payment link verifies', ($pl->verify($tok, $ts)['a'] ?? null) === 15000);
[$p, $sig] = explode('.', $tok);
$forged = rtrim(strtr(base64_encode(json_encode(['m' => 'm_test01', 'a' => 1, 'c' => 'USD', 'r' => 'INV-1001', 'e' => $ts + 86400])), '+/', '-_'), '=') . '.' . $sig;
check('altered amount rejected', $pl->verify($forged, $ts) === null);
check('expired link rejected', $pl->verify($tok, $ts + 90000) === null);

// ---------- Statement CSV
$csv = StatementExporter::csv($in, $r, 'PS-2026-40-0012');
check('CSV has net line 1156.08', str_contains($csv, '"Net payout",1156.08,USD'));

echo PHP_EOL . ($n - $fail) . "/$n passed" . PHP_EOL;
exit($fail ? 1 : 0);
