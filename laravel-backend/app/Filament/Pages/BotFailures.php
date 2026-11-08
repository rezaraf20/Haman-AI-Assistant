<?php
namespace App\Filament\Pages;

use App\Support\PlatformAccess;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Replies where the bot itself failed — empty, a fallback/error, or "I can't
 * do that / didn't follow" (App\Support\AnswerOutcome::OURS) — across every
 * tenant. This is the platform's own to-do list; a shop's missing products
 * ("we don't have that") are deliberately not here, they are in that
 * shop's demand-gap report.
 *
 * Also the measured baselines (answer_quality_baselines), so a fix can be
 * judged against a real number. Read-only: SELECTs only.
 */
class BotFailures extends Page
{
    protected static string $view = 'filament.pages.bot-failures';
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?int $navigationSort = 8;

    public ?string $kindFilter = null;
    public int $days = 30;

    public static function canAccess(): bool { return PlatformAccess::allows('tenant_reports'); }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('tenant_reports'); }
    public static function getNavigationLabel(): string { return __('bot_failures.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_infrastructure'); }
    public function getTitle(): string { return __('bot_failures.nav'); }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function summary(): array
    {
        return DB::table('bot_failures')
            ->where('occurred_at', '>=', now()->subDays($this->days))
            ->selectRaw('kind, count(*) AS n')->groupBy('kind')->pluck('n', 'kind')->all();
    }

    public function rows(): array
    {
        return DB::table('bot_failures AS f')
            ->leftJoin('chatbot_index AS ci', 'ci.chatbot_id', '=', 'f.chatbot_id')
            ->leftJoin('tenants AS t', 't.id', '=', 'f.tenant_id')
            ->where('f.occurred_at', '>=', now()->subDays($this->days))
            ->when($this->kindFilter, fn ($q) => $q->where('f.kind', $this->kindFilter))
            ->orderByDesc('f.occurred_at')
            ->limit(200)
            ->get(['f.kind', 'f.question', 'f.reply', 'f.source', 'f.occurred_at', 'ci.name AS chatbot', 't.name AS tenant'])
            ->all();
    }

    public function baselines(): array
    {
        return DB::table('answer_quality_baselines')->orderByDesc('measured_at')->limit(20)->get()->all();
    }
}
