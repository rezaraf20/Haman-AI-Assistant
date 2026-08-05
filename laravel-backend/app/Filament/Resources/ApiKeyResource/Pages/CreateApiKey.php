<?php
namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class CreateApiKey extends CreateRecord {
    protected static string $resource = ApiKeyResource::class;

    // Bypasses the normal mass-assign-from-form flow: form fields don't include
    // key_prefix/key_hash at all, they're generated here via the same helper
    // TenantController::createApiKey uses, so panel-issued and tenant-issued
    // keys are indistinguishable to AuthenticateTenantApiKey.
    protected function handleRecordCreation(array $data): Model {
        [$key, $plaintext] = ApiKey::generate(
            tenantId: $data['tenant_id'],
            chatbotId: $data['chatbot_id'] ?? null,
            name: $data['name'],
            createdBy: auth()->id(),
            expiresAt: $data['expires_at'] ?? null,
        );

        Notification::make()
            ->title('API key created — copy it now')
            ->body("This will not be shown again:\n\n{$plaintext}")
            ->success()
            ->persistent()
            ->send();

        return $key;
    }
}
