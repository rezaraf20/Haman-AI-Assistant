<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\{Chatbot, Message};
use App\Services\TenantService;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The other half of a trial chatbot's cap (see TenantService::
 * createTrialChatbot()) — ExpireOverdueChatbotsCommand already suspends it
 * once chatbot_index.expires_at passes, but a trial that gets hammered with
 * messages in its first hour would otherwise run free until that date
 * regardless of volume. This checks the message COUNT side of the same cap.
 *
 * Runs per tenant schema (chatbots.type/messages live there, not in the
 * public-schema chatbot_index this ultimately updates) — same
 * SET search_path / reset-per-iteration shape as AggregateAnalyticsJob.
 */
class EnforceTrialMessageLimitCommand extends Command {
    protected $signature   = 'trial-chatbots:enforce-message-limit';
    protected $description = 'Suspend trial chatbots that have answered more than the configured message cap';

    public function handle(TenantService $tenantService): void {
        $limit = (int) Settings::get('limits.trial_chatbot_message_limit');
        $suspended = 0;

        Tenant::active()->chunk(20, function ($tenants) use ($limit, &$suspended, $tenantService) {
            foreach ($tenants as $tenant) {
                DB::statement("SET search_path TO {$tenant->schema_name}, public");

                $trialBots = Chatbot::where('type', 'trial')->where('is_active', true)->get();
                foreach ($trialBots as $bot) {
                    $count = Message::where('chatbot_id', $bot->id)->where('role', 'user')->count();
                    if ($count < $limit) continue;

                    // setChatbotActive() leaves search_path on 'public' when
                    // it returns, so it must be set back to this tenant's
                    // schema before the loop's next Message::where() call.
                    $tenantService->setChatbotActive($bot->id, false, 'trial_message_limit_reached');
                    DB::statement("SET search_path TO {$tenant->schema_name}, public");
                    $suspended++;
                    $this->line("Suspended {$bot->id} ({$bot->name}) — {$count}/{$limit} messages");
                }

                DB::statement('SET search_path TO public');
            }
        });

        $this->info("Suspended {$suspended} trial chatbot(s) over the message limit");
    }
}
