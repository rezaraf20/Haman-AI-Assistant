@php
    // Inline styles throughout — same reasoning as otp-login.blade.php:
    // this page is rendered outside any Filament panel layout, so Filament's
    // PurgeCSS'd compiled CSS doesn't contain the utility classes this file
    // would otherwise reference.
    $isRtl = app()->getLocale() === 'fa';
    $dir = $isRtl ? 'rtl' : 'ltr';
    $fontFamily = $isRtl ? "'Vazirmatn','Tahoma',sans-serif" : "'Inter','Segoe UI',sans-serif";
    $primary = config('haman.brand.primary_color');
    $inputStyle = "width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #CBD5E1;border-radius:8px;font-size:14px;background:#fff;color:#0F172A;font-family:{$fontFamily};";
    $labelStyle = "display:block;font-size:13px;margin-bottom:6px;color:#334155;";
    $btnStyle = "width:100%;padding:10px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;background:{$primary};color:#ffffff;font-family:{$fontFamily};";
    $errStyle = "margin-bottom:16px;border-radius:8px;background:#FEF2F2;color:#B91C1C;font-size:13px;padding:10px 12px;";
    $fieldErrStyle = "color:#DC2626;font-size:12px;";
@endphp
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:{{ $fontFamily }};" dir="{{ $dir }}">
    <div style="width:100%;max-width:380px;background:#ffffff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:28px;">
        <h1 style="font-size:18px;font-weight:700;margin:0 0 20px;text-align:center;color:#0F172A;">{{ __('common.auth_title') }}</h1>

        @if ($error)
            <div style="{{ $errStyle }}">{{ $error }}</div>
        @endif

        @if ($mode === 'login')
            <form wire:submit="submitLogin">
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.email') }}</label>
                    <input type="email" wire:model="email" dir="ltr" style="{{ $inputStyle }}">
                    @error('email') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.password') }}</label>
                    <input type="password" wire:model="password" dir="ltr" style="{{ $inputStyle }}">
                    @error('password') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <button type="submit" style="{{ $btnStyle }}" wire:loading.attr="disabled">{{ __('common.login_button') }}</button>
                <p style="text-align:center;font-size:13px;color:#64748B;margin:16px 0 0;">
                    {{ __('common.no_account_yet') }}
                    <button type="button" wire:click="toggleMode" style="background:none;border:none;color:{{ $primary }};cursor:pointer;padding:0;font-size:13px;">{{ __('common.register_link') }}</button>
                </p>
            </form>
        @else
            <form wire:submit="submitRegister">
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.full_name') }}</label>
                    <input type="text" wire:model="name" style="{{ $inputStyle }}">
                    @error('name') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.email') }}</label>
                    <input type="email" wire:model="email" dir="ltr" style="{{ $inputStyle }}">
                    @error('email') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.password') }}</label>
                    <input type="password" wire:model="password" dir="ltr" style="{{ $inputStyle }}">
                    @error('password') <span style="{{ $fieldErrStyle }}">{{ $message }}</span> @enderror
                </div>
                <div style="margin-bottom:16px;">
                    <label style="{{ $labelStyle }}">{{ __('common.confirm_password') }}</label>
                    <input type="password" wire:model="password_confirmation" dir="ltr" style="{{ $inputStyle }}">
                </div>
                <button type="submit" style="{{ $btnStyle }}" wire:loading.attr="disabled">{{ __('common.register_button') }}</button>
                <p style="text-align:center;font-size:13px;color:#64748B;margin:16px 0 0;">
                    {{ __('common.have_account_already') }}
                    <button type="button" wire:click="toggleMode" style="background:none;border:none;color:{{ $primary }};cursor:pointer;padding:0;font-size:13px;">{{ __('common.login_link') }}</button>
                </p>
            </form>
        @endif
    </div>
</div>
