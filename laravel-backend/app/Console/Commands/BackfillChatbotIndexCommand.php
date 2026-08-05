<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\ChatbotIndexEntry;
use App\Models\Tenant\Chatbot;
use App\Models\Tenant\ChatbotDomain;

/**
 * One-off: populate the new chatbot_index.name / primary_domain columns for
 * chatbots created before those columns existed, by reading each chatbot's
 * own tenant-schema row. Safe to re-run — always re-derives from source.
 */
class BackfillChatbotIndexCommand extends Command {
    protected $signature   = 'chatbots:backfill-index';
    protected $description = 'Populate chatbot_index.name/primary_domain from each chatbot\'s tenant-schema record';

    public function handle(): void {
        DB::statement('SET search_path TO public');
        $entries = ChatbotIndexEntry::whereNull('name')->orWhereNull('primary_domain')->get();

        foreach ($entries as $entry) {
            DB::statement("SET search_path TO {$entry->schema_name}, public");
            $chatbot = Chatbot::find($entry->chatbot_id);
            if (!$chatbot) {
                $this->warn("No Chatbot row for {$entry->chatbot_id} in {$entry->schema_name}, skipping");
                continue;
            }
            $domain = ChatbotDomain::where('chatbot_id', $entry->chatbot_id)->where('is_active', true)->value('domain');

            DB::statement('SET search_path TO public');
            $entry->update(['name' => $chatbot->name, 'primary_domain' => $domain]);
            $this->line("Backfilled {$entry->chatbot_id}: {$chatbot->name} / " . ($domain ?? '(no domain)'));
        }

        $this->info("Backfilled {$entries->count()} chatbot_index entries");
    }
}
