<?php
namespace App\Livewire;

use App\Models\User;
use App\Services\TenantService;
use Illuminate\Support\Facades\{Auth, Hash};
use Livewire\Component;

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

        $user = User::where('email', $this->email)->first();
        if (!$user || !Hash::check($this->password, $user->password_hash ?? $user->password ?? '')) {
            if ($user) { $user->increment('failed_login_count'); }
            $this->error = __('validation.invalid_credentials');
            return;
        }
        if ($user->isLocked()) {
            $this->error = __('validation.account_locked');
            return;
        }

        $user->update(['failed_login_count' => 0, 'last_login_at' => now(), 'last_login_ip' => request()->ip()]);
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
