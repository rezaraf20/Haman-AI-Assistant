<?php
namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Support\PlatformActivity;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlan extends EditRecord {
    protected static string $resource = PlanResource::class;

    /** A price change is a platform action, recorded with both values. */
    protected function handleRecordUpdate(Model $record, array $data): Model {
        $before = array_intersect_key($record->getOriginal(), $data);

        $updated = parent::handleRecordUpdate($record, $data);

        $diff = PlatformActivity::diff($before, array_intersect_key($data, $before ?: $data));
        if (!PlatformActivity::isEmptyDiff($diff)) {
            PlatformActivity::record(
                'plan_changed',
                subjectType: 'plan',
                subjectId: (string) $record->id,
                before: $diff[0],
                after: $diff[1],
            );
        }

        return $updated;
    }
}
