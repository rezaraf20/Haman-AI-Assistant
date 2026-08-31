<?php
namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateApiKey extends CreateRecord {
    protected static string $resource = ApiKeyResource::class;

    // Bypasses the normal mass-assign-from-form flow: form fields don't include
    // key_prefix/key_hash at all, they're generated here via the same helper
    // TenantController::createApiKey uses, so panel-issued and tenant-issued
    // keys are indistinguishable to AuthenticateTenantApiKey.
    //
    // The plaintext key is stashed in the session rather than shown here via a
    // Notification — this page immediately redirects to the resource index
    // after create, and ListApiKeys is what actually displays it (in a modal
    // the admin must explicitly dismiss, with a copy button).
    //
    // Uses put(), not flash(): this panel redirects with Livewire's SPA
    // navigate, which can interleave other background requests (Livewire
    // polling etc.) between the create and the index page actually loading —
    // flash() data only survives exactly one subsequent request and was
    // getting consumed/aged out before ListApiKeys ever read it. put() has no
    // such expiry; ListApiKeys removes it itself once shown.
    protected function handleRecordCreation(array $data): Model {
        [$key, $plaintext] = ApiKey::generate(
            tenantId: $data['tenant_id'],
            chatbotId: $data['chatbot_id'] ?? null,
            name: $data['name'],
            createdBy: auth()->id(),
            // DateTimePicker hands back a plain string, not a Carbon instance.
            expiresAt: filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null,
        );

        session()->put('new_api_key_plaintext', $plaintext);

        return $key;
    }
}
