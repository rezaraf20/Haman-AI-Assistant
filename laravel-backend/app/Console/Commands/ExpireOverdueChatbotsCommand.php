<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\ChatbotIndexEntry;

class ExpireOverdueChatbotsCommand extends Command {
    protected $signature   = 'chatbots:expire-overdue';
    protected $description = 'Suspend chatbots whose admin-set expires_at has passed and are still active';

    public function handle(): void {
        $overdue = ChatbotIndexEntry::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();
        foreach ($overdue as $entry) {
            $entry->update(['is_active' => false]);
            $this->line("Suspended {$entry->chatbot_id} ({$entry->name}) — expired {$entry->expires_at}");
        }
        $this->info("Suspended {$overdue->count()} overdue chatbot(s)");
    }
}
