@php($b = $brand ?? \App\Support\Branding\Brand::platform())
@if ($b->isPartner())
<style nonce="{{ $cspNonce ?? '' }}">:root { --blue: {{ $b->primaryColor }}; --blue-dark: {{ $b->primaryDark() }}; --navy: {{ $b->navyColor }}; --ink: {{ $b->navyColor }}; }</style>
@endif
