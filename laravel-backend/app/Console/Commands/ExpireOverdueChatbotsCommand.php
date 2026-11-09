<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\ChatbotIndexEntry;
use App\Services\TenantService;

class ExpireOverdueChatbotsCommand extends Command {
    protected $signature   = 'chatbots:expire-overdue';
    protected $description = 'Suspend chatbots whose admin-set expires_at has passed and are still active';

    public function handle(TenantService $tenantService): void {
        $overdue = ChatbotIndexEntry::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();
        foreach ($overdue as $entry) {
            // Its renewal invoice is open and still inside the grace
            // period: billing:run is charging it, so it keeps running.
            if (\App\Services\BillingService::inGrace((string) $entry->chatbot_id)) continue;
            $tenantService->setChatbotActive($entry->chatbot_id, false, 'expired');
            $this->line("Suspended {$entry->chatbot_id} ({$entry->name}) — expired {$entry->expires_at}");
        }
        $this->info("Suspended {$overdue->count()} overdue chatbot(s)");
    }
}
