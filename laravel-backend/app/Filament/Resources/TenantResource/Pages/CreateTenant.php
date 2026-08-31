<?php
namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\TenantService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord {
    protected static string $resource = TenantResource::class;

    // Tenant::create() alone would leave this customer with no schema, no
    // login user, and no API key — everything TenantService::register()
    // already does correctly for self-service signups. Reuse it here instead
    // of re-implementing provisioning, then apply whatever plan/status the
    // admin picked (register() always starts a new signup on the free trial,
    // which isn't right for a customer the admin is manually onboarding).
    protected function handleRecordCreation(array $data): Model {
        $result = app(TenantService::class)->register([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],
        ]);

        $tenant = $result['tenant'];
        $tenant->update([
            'phone'   => $data['phone']   ?? null,
            'plan_id' => $data['plan_id'] ?? $tenant->plan_id,
            'status'  => $data['status']  ?? $tenant->status,
        ]);

        return $tenant->fresh();
    }
}
