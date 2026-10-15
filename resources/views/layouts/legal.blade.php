<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="index, follow">
  <title>@yield('title') | AmpliRail</title>
  @php($v = config('amplirail.version'))
  <link rel="stylesheet" href="{{ asset('css/amplirail.css') }}?v={{ $v }}">
  <link rel="stylesheet" href="{{ asset('css/legal.css') }}?v={{ $v }}">
  <script src="{{ asset('js/legal-popup.js') }}?v={{ $v }}" nonce="{{ $cspNonce ?? '' }}" defer></script>
</head>
<body class="legal-body">
  <header class="legal-top">
    <a class="brand" href="{{ route('home') }}"><span class="brand-mark">A</span><strong>AmpliRail</strong></a>
  </header>
  <main class="legal-page">
    <article class="legal-doc" id="legal-content">
      @yield('content')
    </article>
  </main>
  @include('partials.footer')
</body>
</html>
