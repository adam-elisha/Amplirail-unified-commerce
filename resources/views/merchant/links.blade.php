@extends('layouts.merchant')
@section('title', 'Payment requests and invoices')
@section('content')
<section class="card">
  <h2>New payment request</h2>
  <p class="muted small">The amount and expiry are signed into the link, so they cannot be changed by anyone who has it.</p>
  <form method="post" action="{{ route('merchant.links.store') }}" class="inline-form">@csrf
    <input type="hidden" name="form_key" value="{{ $formKey }}">
    <div><label>Amount ({{ $m->currency }})</label><input name="amount" type="text" required placeholder="150.00"></div>
    <div><label>Reference or invoice number</label><input name="reference" type="text" required></div>
    <div><label>Description</label><input name="description" type="text"></div>
    <div><label>Email to (optional)</label><input name="email" type="email"></div>
    <div><label>Expires in hours</label><input name="expires_in_hours" type="number" min="1" max="2160" value="72"></div>
    <div><button class="btn" type="submit">Create link</button></div>
  </form>
</section>
<section class="card">
  <div class="table-wrap"><table><thead><tr><th>Created</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th>Expires</th><th>Link</th></tr></thead><tbody>
    @forelse ($links as $l)
      <tr><td class="small">{{ $l->created_at->format('M j H:i') }}</td><td>{{ $l->reference }}</td><td class="num">{{ money($l->amount_minor, $l->currency) }}</td><td>@include('partials.state', ['state' => $l->status])</td>
        <td class="small">{{ $l->expires_at->format('M j H:i') }}</td><td><button class="btn secondary small" type="button" data-copy="{{ $l->url() }}">Copy link</button></td></tr>
    @empty<tr><td colspan="6" class="muted">No payment requests in {{ $env }}.</td></tr>@endforelse
  </tbody></table></div>
</section>
@endsection
