<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Traits\HasUuid;

class User extends Authenticatable implements FilamentUser {
    use HasUuid, HasApiTokens, Notifiable, SoftDeletes;
    protected $fillable = ['tenant_id','email','phone','first_name','last_name','national_id','address','password','password_hash','name','role','avatar_url','email_verified_at','last_login_at','last_login_ip','failed_login_count','locked_until','preferences'];
    protected $hidden = ['password','password_hash'];
    protected $casts = ['email_verified_at'=>'datetime','last_login_at'=>'datetime','locked_until'=>'datetime','preferences'=>'array'];
    public function getAuthPassword(): string { return $this->password_hash ?? $this->password ?? ''; }
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function isOwner(): bool { return $this->role === 'owner'; }
    public function isLocked(): bool { return $this->locked_until && $this->locked_until->isFuture(); }
<<<<<<< HEAD
    public function canAccessPanel(Panel $panel): bool { return $this->isOwner(); }
=======
<<<<<<< Updated upstream
=======
    public function canAccessPanel(Panel $panel): bool {
        return match ($panel->getId()) {
            'admin'    => $this->isOwner(),
            // Any authenticated tenant user, not just the tenant's owner-role
            // user — the customer panel is scoped per-tenant by tenant_id
            // everywhere it queries data, not by role.
            'customer' => (bool) $this->tenant_id,
            default    => false,
        };
    }
>>>>>>> Stashed changes
>>>>>>> origin/develop
}
