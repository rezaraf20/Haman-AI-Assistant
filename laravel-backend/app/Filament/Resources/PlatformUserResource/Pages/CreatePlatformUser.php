<?php
namespace App\Filament\Resources\PlatformUserResource\Pages;

use App\Filament\Resources\PlatformUserResource;
use App\Models\User;
use App\Support\PlatformAudit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreatePlatformUser extends CreateRecord {
    protected static string $resource = PlatformUserResource::class;

    /**
     * The platform_* columns are deliberately absent from User::$fillable,
     * so they are set explicitly here rather than mass-assigned. That is
     * the whole point: there is exactly one code path that can grant a
     * platform role, and it is this admin-only screen.
     */
    protected function handleRecordCreation(array $data): Model {
        $user = new User();
        $user->fill([
            'first_name' => $data['first_name'] ?? null,
            'last_name'  => $data['last_name'] ?? null,
            'email'      => $data['email'],
            'phone'      => $data['phone'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'name'       => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
            // Platform staff belong to no tenant. A tenant_id here would
            // also hand them the customer panel for that tenant.
            'tenant_id'  => null,
            // Tenant role stays empty: this account has no role inside any
            // tenant, and conflating the two is the bug this design exists
            // to prevent.
            'role'       => null,
        ]);

        // Set by the admin on the next screen, or via a reset link — never
        // left guessable.
        $user->password = Hash::make(Str::random(40));
        $user->password_hash = $user->password;
        $user->email_verified_at = now();

        $user->platform_role       = $data['platform_role'];
        $user->platform_is_active  = (bool) ($data['platform_is_active'] ?? true);
        $user->platform_started_at = $data['platform_started_at'] ?? now()->toDateString();
        $user->platform_display_id = $data['platform_display_id'] ?? null;
        $user->save();

        PlatformAudit::record('staff_created', subjectType: 'user', subjectId: (string) $user->id,
            changes: ['platform_role' => ['before' => null, 'after' => $user->platform_role]]);

        return $user;
    }
}
