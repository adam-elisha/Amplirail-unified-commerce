@extends('layouts.base')
@section('body')
@php
  $can = fn (string $p) => in_array($p, $permissions ?? [], true);
  $nav = [
    'Operate' => [
      ['admin.overview', 'Overview', 'merchants.view'],
      ['admin.approvals', 'Approvals', 'approvals.view'],
      ['admin.merchants.index', 'Merchants', 'merchants.view'],
      ['admin.merchants.create', 'Add merchant', 'merchants.create'],
    ],
    'Payments' => [
      ['admin.transactions.index', 'Transactions', 'payments.view'],
      ['admin.disputes.index', 'Disputes and chargebacks', 'payments.view'],
      ['admin.routing', 'Routing and eligibility', 'routing.view'],
    ],
    'Money' => [
      ['admin.statements.index', 'Payout statements', 'fees.view'],
      ['admin.recon.index', 'Reconciliation', 'recon.view'],
      ['admin.reserves.index', 'Reserves', 'fees.view'],
    ],
    'Platform' => [
      ['admin.management', 'Management', 'audit.view'],
      ['admin.developers', 'Developers and system', 'merchants.view'],
    ],
  ];
@endphp
<div class="shell">
  <aside class="sidebar" aria-label="Admin navigation">
    <a class="brand" href="{{ route('admin.overview') }}"><span class="brand-mark">A</span><span><strong>AmpliRail</strong><span>Admin console</span></span></a>
    @foreach ($nav as $group => $items)
      <div class="nav-group">{{ $group }}</div>
      <nav class="nav">
        @foreach ($items as [$route, $label, $perm])
          @if ($can($perm))
            <a href="{{ route($route) }}" class="{{ request()->routeIs($route.'*') ? 'active' : '' }}">{{ $label }}</a>
          @endif
        @endforeach
      </nav>
    @endforeach
    <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="linkbtn" type="submit">Sign out</button></form>
  </aside>
  <div class="main">
    <header class="topbar">
      <div><div class="crumb">@yield('crumb', 'Operate')</div><h1>@yield('title')</h1></div>
      <div class="top-right">
        <span class="badge">Admin</span>
        <span class="badge {{ config('amplirail.environment_badge') === 'live' ? 'live' : 'sandbox' }}">{{ ucfirst(config('amplirail.environment_badge')) }}</span>
        @if (request()->boolean('sample'))<span class="badge grey">Sample data</span>@endif
        <span class="avatar" title="{{ $currentUser->name ?? '' }}">{{ strtoupper(substr($currentUser->name ?? 'A', 0, 2)) }}</span>
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
