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

    // Mirrors the column default. platform_is_active is not fillable, so a
    // freshly created User would otherwise hold null for it while the row
    // in the database holds true — and PlatformAccess::isStaff() casts that
    // null to false, locking out an account the database considers active.
    protected $attributes = ['platform_is_active' => true];
    protected $casts = ['email_verified_at'=>'datetime','last_login_at'=>'datetime','locked_until'=>'datetime','preferences'=>'array','is_platform_admin'=>'boolean','platform_is_active'=>'boolean','platform_started_at'=>'date'];

    /**
     * platform_role is the source of truth; is_platform_admin is kept in
     * step so existing readers of the old column keep working and the two
     * can never disagree. Both are absent from $fillable — a form or API
     * payload must never be able to grant either.
     */
    protected static function booted(): void {
        static::saving(function (self $user) {
            if ($user->isDirty('platform_role')) {
                $user->is_platform_admin = $user->platform_role === 'admin';
                return;
            }

            // The other direction, for the transition only. The migration
            // backfilled every existing is_platform_admin row, but anything
            // that still grants admin the old way (a seeder, a console
            // command, a DBA) would otherwise be silently locked out, since
            // canAccessPanel() now reads platform_role. Note this promotes
            // ONLY is_platform_admin, a column that has never been fillable
            // — users.role is not consulted here and never will be.
            if ($user->isDirty('is_platform_admin')
                && $user->is_platform_admin
                && $user->platform_role === null) {
                $user->platform_role = 'admin';
            }
        });
    }

    public function isPlatformStaff(): bool { return \App\Support\PlatformAccess::isStaff($this); }
    public function isPlatformAdmin(): bool { return \App\Support\PlatformAccess::isAdmin($this); }
    public function isPlatformSupport(): bool { return \App\Support\PlatformAccess::isSupport($this); }
    public function getAuthPassword(): string { return $this->password_hash ?? $this->password ?? ''; }
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function isOwner(): bool { return $this->role === 'owner'; }
    public function isLocked(): bool { return $this->locked_until && $this->locked_until->isFuture(); }
    public function canAccessPanel(Panel $panel): bool {
        return match ($panel->getId()) {
            // NOT isOwner()/role==='owner' — that role is granted to every
            // tenant's own first user at signup (see TenantService), so
            // checking it here would let any paying customer's account into
            // the cross-tenant admin panel. platform_role is a wholly
            // separate column, null by default, never mass-assignable, and
            // never written by any tenant-facing code path.
            //
            // Support staff reach the same panel but see a restricted
            // subset — see PlatformAccess, and the per-resource
            // canViewAny()/canAccess() checks that enforce it on direct
            // routes, not merely in the navigation.
            //
            // platform_is_active is read here on EVERY request, which is
            // what makes deactivating an account take effect immediately:
            // a still-valid session cookie stops being enough the moment
            // the flag flips.
            'admin'    => \App\Support\PlatformAccess::isStaff($this),
            // Any authenticated tenant user, not just the tenant's owner-role
            // user — the customer panel is scoped per-tenant by tenant_id
            // everywhere it queries data, not by role.
            'customer' => (bool) $this->tenant_id,
            default    => false,
        };
    }
}
