<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', $brand->name ?? 'AmpliRail') · {{ $brand->name ?? 'AmpliRail' }}</title>
  @php($v = config('amplirail.version')) {{-- cache-busting: static files are cached by browsers for years --}}
  <link rel="stylesheet" href="{{ asset('css/amplirail.css') }}?v={{ $v }}">
  <link rel="stylesheet" href="{{ asset('css/legal.css') }}?v={{ $v }}">
  <script src="{{ asset('js/amplirail.js') }}?v={{ $v }}" nonce="{{ $cspNonce ?? '' }}" defer></script>
  @unless (request()->is('l/*')) {{-- pay pages keep their PCI DSS 6.4.3 script inventory minimal --}}
  <script src="{{ asset('js/legal-popup.js') }}?v={{ $v }}" nonce="{{ $cspNonce ?? '' }}" defer></script>
  @endunless
  @include('partials.brand-style')
  @stack('head')
</head>
<body>
  @yield('body')
</body>
</html>
