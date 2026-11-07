{{-- Rendered inside Filament's <p class="fi-header-subheading">, so inline elements only. --}}
<span class="hp-row">
    <span class="hp-strong">{{ $storeName }}</span>
    @if ($site)
        <span aria-hidden="true">·</span>
        <span class="hp-ltr">{{ $site }}</span>
    @endif
    @if ($planName)
        <span class="hp-badge hp-badge--{{ $planTone }}">{{ __('portal.welcome_plan', ['name' => $planName]) }}</span>
    @endif
    @if ($planLine)
        <span>{{ $planLine }}</span>
    @endif
</span>
