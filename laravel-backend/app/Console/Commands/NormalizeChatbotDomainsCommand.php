<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Support\DomainNormalizer;

// One-off cleanup for existing chatbot_index.primary_domain values written
// before DomainNormalizer existed (BuyChatbot/ChatbotResource/addDomain now
// all normalize on write, but rows saved earlier may still have
// "https://Shop.com/" etc., which ValidateChatbotDomain would then compare
// against a normalized Origin and always reject).
class NormalizeChatbotDomainsCommand extends Command {
    protected $signature   = 'chatbots:normalize-domains';
    protected $description = 'Normalize existing chatbot_index.primary_domain values (scheme/www./trailing-slash/case)';

    public function handle(): void {
        $rows = DB::table('chatbot_index')->whereNotNull('primary_domain')->get(['chatbot_id', 'primary_domain']);
        $changed = 0;
        foreach ($rows as $row) {
            $normalized = DomainNormalizer::normalize($row->primary_domain);
            if ($normalized !== $row->primary_domain) {
                DB::table('chatbot_index')->where('chatbot_id', $row->chatbot_id)->update(['primary_domain' => $normalized]);
                $this->line("{$row->chatbot_id}: \"{$row->primary_domain}\" -> \"{$normalized}\"");
                $changed++;
            }
        }
        $this->info("Done — {$changed} of {$rows->count()} row(s) normalized.");
    }
}
