# Round 1: disputes and chargebacks — ALREADY INSTALLED on getpayments101 (2026-10-14)

Files here are exactly what is on the server. Edits already made on the server:
- routes/web.php: `use App\Http\Controllers\Admin\DisputeController;` and `use App\Http\Controllers\Merchant\MerchantDisputeController;`;
  admin routes disputes.index/store/show/resolve/respond (after `->name('webhooks.replay');`);
  merchant routes disputes.index/show/respond/accept (before the merchant `documents/{document}/download` route).
- layouts/admin.blade.php: Payments menu `['admin.disputes.index', 'Disputes and chargebacks', 'payments.view']`.
- layouts/merchant.blade.php: `['merchant.disputes.index', 'Disputes', 'payments.view']`.
- bootstrap/cache/routes-v7.php renamed to routes-v7.php.bak-20261014 (route cache cleared).
Add all of this to the Git repository so the next deploy does not remove it.
