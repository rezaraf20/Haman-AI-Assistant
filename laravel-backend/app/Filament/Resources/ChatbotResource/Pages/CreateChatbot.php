<?php
namespace App\Filament\Resources\ChatbotResource\Pages;

use App\Filament\Resources\ChatbotResource;
use App\Models\ChatbotIndexEntry;
use App\Models\Tenant;
use App\Models\Tenant\Chatbot;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateChatbot extends CreateRecord {
    protected static string $resource = ChatbotResource::class;

    // ChatbotIndexEntry (the model this resource lists) only mirrors a row that
    // must actually live in the *tenant's own schema* as a `chatbots` table
    // record — the public chatbot_index row is just a lookup pointer to it.
    // Creating only the pointer (what a plain CreateRecord would do) would
    // produce a chatbot the public chat API can never actually serve.
    protected function handleRecordCreation(array $data): Model {
        $tenant = Tenant::findOrFail($data['tenant_id']);

        DB::statement("SET search_path TO {$tenant->schema_name}, public");

        $chatbot = Chatbot::create([
            'id'                  => (string) Str::uuid(),
            'name'                => $data['name'],
            'type'                => $data['type'],
            'status'              => 'active',
            'is_active'           => true,
            'embedding_model'     => 'models/text-embedding-004',
            'llm_model'           => 'gemini-1.5-flash',
            'temperature'         => 0.3,
            'retrieval_top_k'     => 8,
            'retrieval_threshold' => 0.60,
            'memory_window'       => 6,
            'language'            => 'fa',
            'response_language'   => 'fa',
        ]);

        DB::statement("SET search_path TO public");
        DB::table('chatbot_index')->upsert([
            'chatbot_id'      => $chatbot->id,
            'tenant_id'       => $tenant->id,
            'schema_name'     => $tenant->schema_name,
            'is_active'       => true,
            'name'            => $data['name'],
            'primary_domain'  => $data['primary_domain'] ?? null,
            'expires_at'      => $data['expires_at'] ?? null,
        ], ['chatbot_id'], ['schema_name', 'is_active', 'name', 'primary_domain', 'expires_at']);

        return ChatbotIndexEntry::findOrFail($chatbot->id);
    }
}
