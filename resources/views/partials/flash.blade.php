@if (session('status'))
  <div class="alert ok" role="status">{{ session('status') }}</div>
@endif
@if (session('error'))
  <div class="alert err" role="alert">{{ session('error') }}</div>
@endif
@isset($revealed)
  {{-- One-time secrets are rendered in this response only; they are never written to the session. --}}
  <div class="alert info" role="status">
    <strong>{{ $revealedLabel ?? 'Secret' }}</strong>, shown once. Copy it now and store it in your secrets manager:
    <div class="secret">{{ $revealed }}</div>
  </div>
@endisset
@if ($errors->any())
  <div class="alert err" role="alert">
    @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
  </div>
@endif
