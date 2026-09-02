@php
    // Inline styles throughout this file on purpose: this page is rendered
    // outside any Filament panel layout, and Filament's compiled app.css
    // (linked in resources/views/auth/otp-login-page.blade.php purely for
    // font/base styling) is PurgeCSS'd down to only the utility classes
    // Filament's own Blade files reference — Tailwind classes written here
    // don't exist in that file, so they render completely unstyled
    // (invisible white-on-white button, unstyled everything). Inline styles
    // sidestep that entirely without needing a separate build pipeline.
    $isRtl = app()->getLocale() === 'fa';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $fontFamily = $isRtl ? "'Vazirmatn','Tahoma',sans-serif" : "'Inter','Segoe UI',sans-serif";
    $primary = '#1B3A6B';
    $inputStyle = "width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;background:#fff;color:#0F172A;font-family:{$fontFamily};";
    $labelStyle = "display:block;font-size:13px;margin-bottom:6px;color:#334155;";
    $btnStyle = "width:100%;padding:10px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;background:{$primary};color:#ffffff;font-family:{$fontFamily};";
    $errStyle = "margin-bottom:16px;border-radius:8px;background:#FEF2F2;color:#B91C1C;font-size:13px;padding:10px 12px;";
    $infoStyle = "margin-bottom:16px;border-radius:8px;background:#F0FDF4;color:#15803D;font-size:13px;padding:10px 12px;";
    $fieldErrStyle = "color:#DC2626;font-size:12px;";
@endphp
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:{{ $fontFamily }};" dir="{{ $dir }}">
    <div style="width:100%;max-width:380px;background:#ffffff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:28px;">
        <h1 style="font-size:18px;font-weight:700;margin:0 0 20px;text-align:center;color:#0F172A;">{{ __('common.auth_title') }}</h1>

        @if ($error)
            <div style="{{ $errStyle }}">{{ $error }}</div>
        @endif
        @if ($info)
            <div style="{{ $infoStyle }}">{{ $info }}</div>
        @endif

        @if ($step === 'phone')
            <form wire:submit="sendCode">
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('panel.mobile_number') }}</label>
                    <input type="tel" wire:model="phone" placeholder="09123456789" dir="ltr" style="{{ $inputStyle }}">
                    @error('phone') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <button type="submit" style="{{ $btnStyle }}" wire:loading.attr="disabled">{{ __('common.send_otp_button') }}</button>
            </form>
        @elseif ($step === 'otp')
            <form wire:submit="verifyCode">
                <p style="font-size:13px;color:#64748B;margin:0 0 16px;">{{ __('common.otp_sent_to', ['phone' => $phone]) }}</p>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.otp_code_label') }}</label>
                    <input type="text" inputmode="numeric" wire:model="code" maxlength="5" dir="ltr"
                        style="{{ $inputStyle }} text-align:center;letter-spacing:4px;">
                    @error('code') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <button type="submit" style="{{ $btnStyle }}" wire:loading.attr="disabled">{{ __('common.confirm_button') }}</button>
                <div style="display:flex;justify-content:space-between;margin-top:14px;font-size:13px;">
                    <button type="button" wire:click="backToPhone" style="background:none;border:none;color:#64748B;cursor:pointer;">{{ __('common.change_number') }}</button>
                    <button type="button" wire:click="resendCode" style="background:none;border:none;color:{{ $primary }};cursor:pointer;">{{ __('common.resend_code') }}</button>
                </div>
            </form>
        @elseif ($step === 'profile')
            <form wire:submit="completeProfile">
                <p style="font-size:13px;color:#64748B;margin:0 0 16px;">{{ __('common.complete_signup_intro') }}</p>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('panel.first_name') }}</label>
                    <input type="text" wire:model="first_name" style="{{ $inputStyle }}">
                    @error('first_name') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('panel.last_name') }}</label>
                    <input type="text" wire:model="last_name" style="{{ $inputStyle }}">
                    @error('last_name') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('panel.national_id') }}</label>
                    <input type="text" wire:model="national_id" dir="ltr" style="{{ $inputStyle }}">
                    @error('national_id') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.email') }}</label>
                    <input type="email" wire:model="email" dir="ltr" style="{{ $inputStyle }}">
                    @error('email') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.address') }}</label>
                    <textarea wire:model="address" rows="2" style="{{ $inputStyle }}"></textarea>
                    @error('address') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <button type="submit" style="{{ $btnStyle }}" wire:loading.attr="disabled">{{ __('common.complete_and_login') }}</button>
            </form>
        @endif
    </div>
</div>
