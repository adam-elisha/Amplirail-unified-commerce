@php($b = $brand ?? \App\Support\Branding\Brand::platform())
@if ($b->logoData)<img class="brand-mark" src="{{ $b->logoData }}" alt="{{ $b->name }}">@else<span class="brand-mark">{{ $b->mark }}</span>@endif
