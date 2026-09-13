<?php
namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Support\PlatformActivity;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTenant extends EditRecord {
    protected static string $resource = TenantResource::class;

    /**
     * Editing a tenant is admin-only (TenantResource::canEdit), but "an
     * admin changed something" is not reviewable — which plan, from what,
     * to what, is. So the same before/after record is written here as for
     * a chatbot's settings.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model {
        $before = array_intersect_key($record->getOriginal(), $data);

        $updated = parent::handleRecordUpdate($record, $data);

        $diff = PlatformActivity::diff($before, array_intersect_key($data, $before ?: $data));
        if (!PlatformActivity::isEmptyDiff($diff)) {
            PlatformActivity::record(
                'tenant_settings_changed',
                tenantId: (string) $record->id,
                subjectType: 'tenant',
                subjectId: (string) $record->id,
                before: $diff[0],
                after: $diff[1],
            );
        }

        return $updated;
    }
}
