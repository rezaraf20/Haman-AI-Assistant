<?php
namespace App\Livewire;

use App\Models\User;
use App\Services\SmsService;
use App\Services\TenantService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

// Phone+SMS-OTP login/signup for the customer portal (/portal). Deliberately
// not a Filament auth page — see CustomerPanelProvider for why. Establishes a
// normal 'web' guard session via Auth::login(), which is all Filament's own
// Authenticate middleware ever checks for, so /portal treats this exactly
// like a native Filament login afterwards.
class OtpLogin extends Component {
    public string $step = 'phone'; // phone | otp | profile

    public string $phone = '';
    public string $code = '';

    public string $first_name = '';
    public string $last_name = '';
    public string $national_id = '';
    public string $email = '';
    public string $address = '';

    public ?string $error = null;
    public ?string $info = null;

    public function sendCode(SmsService $sms): void {
        $this->error = null;
        $this->validate(['phone' => ['required', 'regex:/^09\d{9}$/']], [
            'phone.regex' => 'شماره موبایل را به‌صورت صحیح وارد کنید (مثال: 09123456789).',
        ]);

        $result = $sms->sendOtp($this->phone);
        if (!$result['ok']) {
            $this->error = $result['message'];
            return;
        }
        $this->info = 'کد تایید ارسال شد.';
        $this->step = 'otp';
    }

    public function resendCode(SmsService $sms): void {
        $this->error = null;
        $this->info = null;
        $result = $sms->sendOtp($this->phone);
        if (!$result['ok']) {
            $this->error = $result['message'];
            return;
        }
        $this->info = 'کد تایید مجدداً ارسال شد.';
    }

    public function verifyCode(SmsService $sms): void {
        $this->error = null;
        $this->validate(['code' => 'required|digits:5']);

        $result = $sms->verifyOtp($this->phone, $this->code);
        if (!$result['ok']) {
            $this->error = $result['message'];
            return;
        }

        $existing = User::where('phone', $this->phone)->first();
        if ($existing) {
            Auth::guard('web')->login($existing, remember: true);
            $this->redirect('/portal', navigate: false);
            return;
        }

        $this->step = 'profile';
    }

    public function completeProfile(TenantService $tenantService): void {
        $this->error = null;
        $this->validate([
            'first_name'  => 'required|string|max:100',
            'last_name'   => 'required|string|max:100',
            'national_id' => 'required|string|max:20',
            'email'       => 'required|email|max:255|unique:users,email',
            'address'     => 'required|string|max:1000',
        ], [], [
            'first_name'  => 'نام',
            'last_name'   => 'نام خانوادگی',
            'national_id' => 'کد ملی',
            'email'       => 'ایمیل',
            'address'     => 'آدرس',
        ]);

        $result = $tenantService->registerViaPhone([
            'phone'       => $this->phone,
            'first_name'  => $this->first_name,
            'last_name'   => $this->last_name,
            'national_id' => $this->national_id,
            'email'       => $this->email,
            'address'     => $this->address,
        ]);

        Auth::guard('web')->login($result['user'], remember: true);
        $this->redirect('/portal', navigate: false);
    }

    public function backToPhone(): void {
        $this->step = 'phone';
        $this->code = '';
        $this->error = null;
        $this->info = null;
    }

    public function render() {
        return view('livewire.otp-login');
    }
}
