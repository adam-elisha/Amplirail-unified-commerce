@extends('layouts.base')
@section('title', 'Payment')
@section('body')
<main class="pay">
  <div class="card">
    @if ($status === 'approved')
      <h1>Payment received</h1>
      <p>Thank you. {{ $merchant }} received {{ $amount }} for {{ $reference }}.</p>
      <p class="muted small">Receipt reference {{ $transactionId }}</p>
    @else
      <h1>Payment not completed</h1>
      <p>Your payment of {{ $amount }} to {{ $merchant }} did not go through. No money was taken. You can try again with another card.</p>
      <a class="btn secondary" href="{{ url()->previous() }}">Try again</a>
    @endif
  </div>
  @include('partials.footer')
</main>
@endsection
