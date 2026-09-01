<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Diagnostic for ValidateChatbotDomain's enforcement being effectively off:
// primary_domain is nullable and enforcement is soft when it's null, so if
// BackfillChatbotIndexCommand (which reads from the never-populated
// chatbot_domains table) left every row null, domain validation is
// currently a no-op for every tenant regardless of the middleware being wired up.
class ChatbotDomainReportCommand extends Command {
    protected $signature   = 'chatbots:domain-report';
    protected $description = 'Report primary_domain (the field ValidateChatbotDomain enforces against) for every active chatbot';

    public function handle(): void {
        $rows = DB::table('chatbot_index')->where('is_active', true)->get(['chatbot_id', 'name', 'primary_domain']);
        $emptyCount = 0;
        $this->table(
            ['chatbot_id', 'name', 'primary_domain', 'empty?'],
            $rows->map(function ($r) use (&$emptyCount) {
                $empty = !$r->primary_domain;
                if ($empty) $emptyCount++;
                return [$r->chatbot_id, $r->name ?? '(no name)', $r->primary_domain ?? 'NULL', $empty ? 'YES — unenforced' : 'no'];
            })->toArray()
        );
        $this->info("{$rows->count()} active chatbot(s), {$emptyCount} with no primary_domain (domain check is a no-op for those).");
    }
}
