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
    // is_platform_admin is deliberately absent from $fillable — it must
    // never be settable through mass assignment (a Filament form, an API
    // payload, anything). The only writers are the one-time migration that
    // grants it and a human with direct database access.
    protected $fillable = ['tenant_id','email','phone','first_name','last_name','national_id','address','password','password_hash','name','role','avatar_url','email_verified_at','last_login_at','last_login_ip','failed_login_count','locked_until','preferences','locale'];
    protected $hidden = ['password','password_hash'];
    protected $casts = ['email_verified_at'=>'datetime','last_login_at'=>'datetime','locked_until'=>'datetime','preferences'=>'array','is_platform_admin'=>'boolean'];
    public function getAuthPassword(): string { return $this->password_hash ?? $this->password ?? ''; }
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function isOwner(): bool { return $this->role === 'owner'; }
    public function isLocked(): bool { return $this->locked_until && $this->locked_until->isFuture(); }
    public function canAccessPanel(Panel $panel): bool {
        return match ($panel->getId()) {
            // NOT isOwner()/role==='owner' — that role is granted to every
            // tenant's own first user at signup (see TenantService), so
            // checking it here would let any paying customer's account into
            // the cross-tenant admin panel. is_platform_admin is a wholly
            // separate flag, false by default, set only via a one-off
            // migration for a real platform operator's account.
            'admin'    => (bool) $this->is_platform_admin,
            // Any authenticated tenant user, not just the tenant's owner-role
            // user — the customer panel is scoped per-tenant by tenant_id
            // everywhere it queries data, not by role.
            'customer' => (bool) $this->tenant_id,
            default    => false,
        };
    }
}
