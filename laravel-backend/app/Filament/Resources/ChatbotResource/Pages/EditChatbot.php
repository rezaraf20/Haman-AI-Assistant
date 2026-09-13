<?php
namespace App\Filament\Resources\ChatbotResource\Pages;

use App\Filament\Resources\ChatbotResource;
use App\Models\Tenant\Chatbot;
use App\Support\PlatformAudit;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditChatbot extends EditRecord {
    protected static string $resource = ChatbotResource::class;

    // The form's retrieval_threshold/reranker_enabled/rerank_threshold
    // fields aren't columns on this page's own model (ChatbotIndexEntry,
    // public schema) — they live on the tenant-schema chatbots row. Loaded
    // here by switching search_path, same pattern as MyChatbots.php's
    // widget-appearance action on the customer side.
    protected function mutateFormDataBeforeFill(array $data): array {
        $record = $this->getRecord();
        DB::statement("SET search_path TO {$record->schema_name}, public");
        $chatbot = Chatbot::find($record->chatbot_id);
        DB::statement('SET search_path TO public');

        if ($chatbot) {
            $data['retrieval_threshold'] = $chatbot->retrieval_threshold;
            $data['reranker_enabled']    = $chatbot->reranker_enabled;
            $data['rerank_threshold']    = $chatbot->rerank_threshold;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model {
        $retrievalThreshold = $data['retrieval_threshold'] ?? null;
        $rerankerEnabled    = $data['reranker_enabled'] ?? null;
        $rerankThreshold    = $data['rerank_threshold'] ?? null;
        unset($data['retrieval_threshold'], $data['reranker_enabled'], $data['rerank_threshold']);

        // Changing a customer's settings is the most common support request,
        // so support can do it — but every change is recorded with the value
        // before and after. "Support edited something" is not reviewable;
        // "support changed welcome_message from X to Y" is.
        $before = array_intersect_key($record->getOriginal(), $data);

        $updated = parent::handleRecordUpdate($record, $data);

        $changes = PlatformAudit::diff($before, array_intersect_key($data, $before ?: $data));
        if ($changes) {
            PlatformAudit::record(
                'widget_settings_changed',
                tenantId: (string) $record->tenant_id,
                subjectType: 'chatbot',
                subjectId: (string) $record->chatbot_id,
                changes: $changes,
            );
        }

        DB::statement("SET search_path TO {$record->schema_name}, public");
        $chatbot = Chatbot::find($record->chatbot_id);
        if ($chatbot) {
            $chatbot->update(array_filter([
                'retrieval_threshold' => $retrievalThreshold,
                'reranker_enabled'    => $rerankerEnabled,
                'rerank_threshold'    => $rerankThreshold,
            ], fn ($v) => $v !== null));
        }
        DB::statement('SET search_path TO public');

        return $updated;
    }
}
