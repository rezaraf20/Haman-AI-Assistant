<?php
namespace App\Filament\Resources\PlatformUserResource\Pages;

use App\Filament\Resources\PlatformUserResource;
use App\Support\PlatformAudit;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlatformUser extends EditRecord {
    protected static string $resource = PlatformUserResource::class;

    /** Same reasoning as CreatePlatformUser: platform_* is never mass-assigned. */
    protected function handleRecordUpdate(Model $record, array $data): Model {
        $before = [
            'platform_role'      => $record->platform_role,
            'platform_is_active' => (bool) $record->platform_is_active,
        ];

        $record->fill([
            'first_name' => $data['first_name'] ?? null,
            'last_name'  => $data['last_name'] ?? null,
            'email'      => $data['email'],
            'phone'      => $data['phone'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'name'       => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
        ]);

        $record->platform_role       = $data['platform_role'];
        $record->platform_is_active  = (bool) ($data['platform_is_active'] ?? true);
        $record->platform_started_at = $data['platform_started_at'] ?? null;
        $record->platform_display_id = $data['platform_display_id'] ?? null;
        $record->save();

        // Revoking the role or the account must not leave API tokens alive.
        if (!$record->platform_is_active) {
            $record->tokens()->delete();
        }

        $after = [
            'platform_role'      => $record->platform_role,
            'platform_is_active' => (bool) $record->platform_is_active,
        ];
        $changes = PlatformAudit::diff($before, $after);
        if ($changes) {
            PlatformAudit::record('staff_updated', subjectType: 'user',
                subjectId: (string) $record->id, changes: $changes);
        }

        return $record;
    }
}
