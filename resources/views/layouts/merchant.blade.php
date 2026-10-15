@extends('layouts.base')
@section('body')
@php
  $env = session('environment', 'sandbox');
  $can = fn (string $p) => in_array($p, $permissions ?? [], true);
  $nav = [
    ['merchant.dashboard', 'Dashboard', 'payments.view'],
    ['merchant.transactions.index', 'Transactions and reports', 'payments.view'],
    ['merchant.disputes.index', 'Disputes', 'payments.view'],
    ['merchant.alerts.index', 'Dispute alerts', 'payments.view'],
    ['merchant.terminal', 'Virtual terminal', 'payments.refund'],
    ['merchant.links.index', 'Payment requests', 'payments.refund'],
    ['merchant.statements.index', 'Payout statements', 'fees.view'],
    ['merchant.documents.index', 'Documents', 'documents.view'],
    ['merchant.developers', 'Developers and API keys', 'api_keys.manage'],
  ];
@endphp
<div class="shell">
  <aside class="sidebar" aria-label="Merchant navigation">
    <a class="brand" href="{{ route('merchant.dashboard') }}">@include('partials.brand-mark')<span><strong>{{ $brand->name ?? 'AmpliRail' }}</strong><span>Merchant portal</span></span></a>
    <nav class="nav">
      @foreach ($nav as [$route, $label, $perm])
        @if ($can($perm))
          <a href="{{ route($route) }}" class="{{ request()->routeIs($route.'*') ? 'active' : '' }}">{{ $label }}</a>
        @endif
      @endforeach
    </nav>
    <form method="post" action="{{ route('merchant.logout') }}">@csrf<button class="linkbtn" type="submit">Sign out</button></form>
  </aside>
  <div class="main">
    <header class="topbar">
      <div><div class="crumb">@yield('crumb', 'Merchant')</div><h1>@yield('title')</h1></div>
      <div class="top-right">
        <form method="post" action="{{ route('merchant.environment') }}" class="actions">
          @csrf
          <input type="hidden" name="environment" value="{{ $env === 'live' ? 'sandbox' : 'live' }}">
          <span class="badge {{ $env }}">{{ ucfirst($env) }}</span>
          <button class="btn secondary small" type="submit">Switch to {{ $env === 'live' ? 'sandbox' : 'live' }}</button>
        </form>
        <span class="avatar" title="{{ $currentUser->name ?? '' }}">{{ strtoupper(substr($currentUser->name ?? 'M', 0, 2)) }}</span>
      </div>
    </header>
    <main class="content">
      @include('partials.flash')
      @yield('content')
    </main>
    @include('partials.footer')
  </div>
</div>
@endsection
