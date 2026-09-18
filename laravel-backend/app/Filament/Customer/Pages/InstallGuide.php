<?php
namespace App\Filament\Customer\Pages;

use App\Support\BrandDomains;
use App\Models\ApiKey;
use App\Models\ChatbotIndexEntry;
use App\Support\CustomerOnboarding;
use Filament\Pages\Page;

/**
 * Step-by-step setup, starting from wherever the customer actually is.
 *
 * The state is not guessed or stored: CustomerOnboarding already derives it
 * from real signals — a chatbot row, an API key that has actually been used
 * (which is the closest thing to "the plugin is installed"), a completed sync
 * job, and a first conversation. This page reuses that rather than inventing
 * a second, disagreeing notion of progress.
 */
class InstallGuide extends Page
{
    protected static string $view = 'filament.customer.pages.install-guide';
    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';
    protected static ?int $navigationSort = -10;

    public static function getNavigationLabel(): string { return __('install_guide.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('install_guide.title'); }

    /** A badge only while there is something left to do. */
    public static function getNavigationBadge(): ?string
    {
        $tenant = auth()->user()?->tenant;
        if (!$tenant) return null;

        $done = collect(static::stepStates($tenant))->filter()->count();
        $total = count(static::stepKeys());

        return $done >= $total ? null : ($done . '/' . $total);
    }

    public static function getNavigationBadgeColor(): ?string { return 'warning'; }

    /** @return string[] */
    public static function stepKeys(): array
    {
        return ['chatbot', 'download', 'connect', 'sync', 'tools'];
    }

    /**
     * @return array<string, bool> step key => done
     */
    public static function stepStates($tenant): array
    {
        $status = CustomerOnboarding::status($tenant);

        return [
            'chatbot'  => $status['chatbot_created'],
            // No separate signal for "downloaded": once the key has been
            // used, the plugin is demonstrably installed and running, which
            // covers both.
            'download' => $status['plugin_installed'],
            'connect'  => $status['plugin_installed'],
            'sync'     => $status['first_sync_done'],
            'tools'    => $status['any_tool_enabled'] ?? false,
        ];
    }

    public function states(): array
    {
        return static::stepStates(auth()->user()->tenant);
    }

    /** The first unfinished step, so the page opens where the work is. */
    public function currentStep(): string
    {
        foreach (static::stepStates(auth()->user()->tenant) as $key => $done) {
            if (!$done) return $key;
        }

        return 'tools';
    }

    public function chatbots()
    {
        return ChatbotIndexEntry::where('tenant_id', auth()->user()->tenant_id)->get();
    }

    /**
     * Key prefixes only. The full value is shown once, when it is created,
     * on the API keys page — never re-displayed here.
     */
    public function apiKeys()
    {
        return ApiKey::where('tenant_id', auth()->user()->tenant_id)
            ->get(['id', 'name', 'key_prefix', 'last_used_at', 'chatbot_id']);
    }

    public function pluginDownloadUrl(): string
    {
        return url('/downloads/haman-ai-chatbot.zip');
    }

    /**
     * The API address the customer pastes into their own site.
     *
     * Not derived from app.url, which follows whichever hostname the panel
     * was opened on -- a customer who reached the panel through
     * app.hamanai.com would otherwise be handed app.hamanai.com as their API
     * base, which is not the address this platform wants embedded in other
     * people's websites for the next several years. It comes from
     * haman.domains.api_public instead, a value that changes deliberately and
     * on its own schedule.
     */
    public function apiBaseUrl(): string
    {
        return BrandDomains::publicApiUrl('/api/v1');
    }
}
