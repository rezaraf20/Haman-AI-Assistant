<?php
namespace App\Livewire;

use App\Models\User;
use App\Services\TenantService;
use Illuminate\Support\Facades\{Auth, Hash};
use Livewire\Component;
use App\Support\Settings;
use Illuminate\Support\Facades\RateLimiter;

// Email+password login/signup for the customer portal (/portal) —
// English-locale counterpart to OtpLogin's Persian-locale phone+SMS-OTP
// flow (see routes/web.php's /portal/login for how the two are chosen).
// Same session-based approach as OtpLogin: Auth::login() on the 'web' guard
// is all Filament's Authenticate middleware ever checks, so /portal treats
// this exactly like a native Filament login afterwards.
class EmailLogin extends Component {
    public string $mode = 'login'; // login | register

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public ?string $error = null;

    public function toggleMode(): void {
        $this->mode = $this->mode === 'login' ? 'register' : 'login';
        $this->error = null;
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetValidation();
    }

    public function submitLogin(): void {
        $this->error = null;
        $this->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // A Livewire action arrives on /livewire/update, so the throttle on
        // /portal/login never covered this — password guessing here ran at
        // full speed. Keyed on both IP and address, so one attacker cannot
        // lock every account out from a single IP.
        $perMinute = (int) Settings::get('limits.login_attempts_per_minute');
        foreach (['login:ip:' . request()->ip(), 'login:email:' . strtolower($this->email)] as $key) {
            if (RateLimiter::tooManyAttempts($key, $perMinute)) {
                $this->error = __('validation.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)]);
                return;
            }
        }

        $user = User::where('email', $this->email)->first();

        // Before the password check, so a locked account cannot be probed by
        // timing which wrong passwords come back slower.
        if ($user && $user->isLocked()) {
            $this->error = __('validation.account_locked');
            return;
        }

        if (!$user || !Hash::check($this->password, $user->password_hash ?? $user->password ?? '')) {
            foreach (['login:ip:' . request()->ip(), 'login:email:' . strtolower($this->email)] as $key) {
                RateLimiter::hit($key, 60);
            }

            // failed_login_count was incremented here but locked_until was
            // never set, so isLocked() could never be true. It locks now.
            if ($user) {
                $user->increment('failed_login_count');
                $ceiling = (int) Settings::get('limits.login_attempts_before_lockout');
                if ($ceiling > 0 && $user->fresh()->failed_login_count >= $ceiling) {
                    $user->update([
                        'locked_until'       => now()->addMinutes((int) Settings::get('limits.login_lockout_minutes')),
                        'failed_login_count' => 0,
                    ]);
                }
            }

            $this->error = __('validation.invalid_credentials');
            return;
        }

        $user->update(['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => request()->ip()]);
        Auth::guard('web')->login($user, remember: true);
        $this->redirect('/portal', navigate: false);
    }

    public function submitRegister(TenantService $tenantService): void {
        $this->error = null;
        $this->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $result = $tenantService->registerViaEmail([
            'name'     => $this->name,
            'email'    => $this->email,
            'password' => $this->password,
        ]);

        Auth::guard('web')->login($result['user'], remember: true);
        $this->redirect('/portal', navigate: false);
    }

    public function render() {
        return view('livewire.email-login');
    }
}
