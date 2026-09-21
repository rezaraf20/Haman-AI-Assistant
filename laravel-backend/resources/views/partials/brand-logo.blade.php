{{--
    The full logo lockup: mark + wordmark, inline SVG rather than an <img>.

    Inline, on purpose. The wordmark is real <text>, styled with
    font-family:var(--font-heading) — an inline SVG in the DOM inherits the
    page's own loaded @font-face rules exactly like any other element, so it
    renders in self-hosted Poppins wherever this partial is included. A
    standalone hamanai-logo.svg referenced via <img src> would not have that
    guarantee: an image is a separate rendering context that does not see
    the parent page's fonts, which is exactly why the mark-only SVGs in
    resources/brand/ carry no text at all — those ARE meant to be referenced
    as standalone files (favicon, Filament brandLogo, anywhere a plain image
    URL is required) and had to stay font-independent to work everywhere.

    The lockup never mirrors for RTL: the mark stays before the wordmark in
    both languages, the way a logo does in every real brand system — a logo
    is a fixed unit, not prose.

    Props:
      height  int    the SVG's rendered height in px (width follows, ~3.2x)
      variant string 'color' (default) or 'light' for a dark background
      tag     string wrapping element, default 'span'
--}}
@php
    $height = $height ?? 32;
    $variant = $variant ?? 'color';
    $tag = $tag ?? 'span';
    $wordColor = $variant === 'light' ? '#FFFFFF' : 'var(--brand-navy, #001030)';
    $aiFill = $variant === 'light' ? '#FFFFFF' : 'url(#brand-logo-ai-' . $variant . ')';
@endphp
<{{ $tag }} class="brand-logo" style="display:inline-flex;align-items:center;gap:.5em;line-height:1">
    <svg viewBox="0 0 112 131" width="{{ round($height * 112 / 131) }}" height="{{ $height }}" role="img" aria-label="Haman AI" style="flex-shrink:0">
        <defs>
            <linearGradient id="brand-logo-mark-{{ $variant }}" x1="0" y1="0" x2="112" y2="131" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#0098F8"/>
                <stop offset="0.5" stop-color="#4870F8"/>
                <stop offset="1" stop-color="#7050F8"/>
            </linearGradient>
            <linearGradient id="brand-logo-bubble-{{ $variant }}" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#0098F8"/>
                <stop offset="1" stop-color="#4870F8"/>
            </linearGradient>
        </defs>
        @if ($variant === 'light')
            <path d="M 44 9 L 11 34 L 11 116 C 11 120, 13 122, 17 121 C 26 112, 39 98, 46 84 L 46 40 C 46 30, 45 18, 44 9 Z" fill="#FFFFFF"/>
            <path d="M 68 9 L 101 34 L 101 116 C 101 120, 99 122, 95 121 C 86 112, 73 98, 66 84 L 66 40 C 66 30, 67 18, 68 9 Z" fill="#FFFFFF" fill-opacity="0.82"/>
            <path d="M 27 47 h 58 a 15 15 0 0 1 15 15 v 5 a 15 15 0 0 1 -15 15 h -33 q -9 7, -13 7 q -3 0, -2 -3 q 1 -2, 2 -4 h -12 a 15 15 0 0 1 -15 -15 v -5 a 15 15 0 0 1 15 -15 Z" fill="none" stroke="#001030" stroke-width="5.5"/>
            <path d="M 27 47 h 58 a 15 15 0 0 1 15 15 v 5 a 15 15 0 0 1 -15 15 h -33 q -9 7, -13 7 q -3 0, -2 -3 q 1 -2, 2 -4 h -12 a 15 15 0 0 1 -15 -15 v -5 a 15 15 0 0 1 15 -15 Z" fill="#001030"/>
        @else
            <path d="M 44 9 L 11 34 L 11 116 C 11 120, 13 122, 17 121 C 26 112, 39 98, 46 84 L 46 40 C 46 30, 45 18, 44 9 Z" fill="url(#brand-logo-mark-{{ $variant }})"/>
            <path d="M 68 9 L 101 34 L 101 116 C 101 120, 99 122, 95 121 C 86 112, 73 98, 66 84 L 66 40 C 66 30, 67 18, 68 9 Z" fill="url(#brand-logo-mark-{{ $variant }})"/>
            <path d="M 27 47 h 58 a 15 15 0 0 1 15 15 v 5 a 15 15 0 0 1 -15 15 h -33 q -9 7, -13 7 q -3 0, -2 -3 q 1 -2, 2 -4 h -12 a 15 15 0 0 1 -15 -15 v -5 a 15 15 0 0 1 15 -15 Z" fill="none" stroke="#FFFFFF" stroke-width="5.5"/>
            <path d="M 27 47 h 58 a 15 15 0 0 1 15 15 v 5 a 15 15 0 0 1 -15 15 h -33 q -9 7, -13 7 q -3 0, -2 -3 q 1 -2, 2 -4 h -12 a 15 15 0 0 1 -15 -15 v -5 a 15 15 0 0 1 15 -15 Z" fill="url(#brand-logo-bubble-{{ $variant }})"/>
        @endif
        <circle cx="46" cy="62" r="3.2" fill="#fff"/>
        <circle cx="56" cy="62" r="3.2" fill="#fff"/>
        <circle cx="66" cy="62" r="3.2" fill="#fff"/>
    </svg>
    <svg viewBox="0 0 158 40" height="{{ round($height * 0.72) }}" role="presentation" style="flex-shrink:0">
        <defs>
            <linearGradient id="brand-logo-ai-{{ $variant }}" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#0098F8"/>
                <stop offset="1" stop-color="#7050F8"/>
            </linearGradient>
        </defs>
        <text x="0" y="29" font-family="Poppins, Vazirmatn, sans-serif" font-weight="700" font-size="27" fill="{{ $wordColor }}">Haman</text>
        <text x="105" y="29" font-family="Poppins, Vazirmatn, sans-serif" font-weight="700" font-size="27" fill="{{ $aiFill }}">AI</text>
    </svg>
</{{ $tag }}>
