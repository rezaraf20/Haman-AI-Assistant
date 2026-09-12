<?php
namespace App\Filament\Customer\Pages;

use App\Services\SuggestionEngine;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * doc-07's "actions": the Trends page shows what happened, this one says
 * what to do about it. Every row is a rule firing on a real count, with
 * the real conversations behind it one click away — no LLM involved, so a
 * merchant can always be told exactly why they are seeing something.
 *
 * Reads only the suggestions table, written nightly by
 * hamman:generate-suggestions.
 */
class Suggestions extends Page
{
    protected static string $view = 'filament.customer.pages.suggestions';
    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    public static function getNavigationLabel(): string { return __('suggestions.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('suggestions.nav'); }

    /** A count badge is the honest way to say "you have things to act on". */
    public static function getNavigationBadge(): ?string
    {
        $count = static::activeCount();
        return $count > 0 ? (string) $count : null;
    }

    private static function activeCount(): int
    {
        $tenant = auth()->user()?->tenant;
        if (!$tenant) return 0;

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            return DB::table('suggestions')->where('status', 'active')->count();
        } catch (\Throwable $e) {
            return 0;
        } finally {
            DB::statement('SET search_path TO public');
        }
    }

    public function getSuggestions(): array
    {
        $tenant = auth()->user()->tenant;

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            $rows = DB::table('suggestions')
                ->where('status', 'active')
                ->orderByDesc('count')
                ->limit(SuggestionEngine::MAX_ACTIVE)
                ->get();
        } catch (\Throwable $e) {
            $rows = collect();
        } finally {
            DB::statement('SET search_path TO public');
        }

        return $rows->map(function ($r) {
            $params = json_decode((string) $r->params, true) ?: [];
            $conversations = json_decode((string) $r->source_conversation_ids, true) ?: [];
            return [
                'id'            => $r->id,
                'type'          => $r->type,
                'count'         => (int) $r->count,
                'params'        => $params,
                'conversations' => $conversations,
                'text'          => $this->describe($r->type, (int) $r->count, $params),
                'action'        => $this->actionFor($r->type, $params),
            ];
        })->all();
    }

    /**
     * The sentence the merchant reads. Built from the rule's own numbers —
     * there is no generation step here, just a translated template per
     * rule, which is why the same data always produces the same sentence.
     */
    private function describe(string $type, int $count, array $params): string
    {
        return match ($type) {
            'topic_gap' => __('suggestions.text_topic_' . ($params['topic'] ?? 'other'), [
                'count' => $count,
            ]),
            'repeated_question' => __('suggestions.text_repeated_question', [
                'count' => $count, 'question' => $params['question'] ?? '',
            ]),
            'unanswered_topic' => __('suggestions.text_unanswered', [
                'count' => $count, 'question' => $params['question'] ?? '',
            ]),
            'missing_from_catalog' => __('suggestions.text_missing', [
                'count' => $count, 'request' => $params['request'] ?? '',
            ]),
            'compared_pair' => __('suggestions.text_compared', [
                'count' => $count, 'products' => implode(' + ', $params['product_ids'] ?? []),
            ]),
            default => __('suggestions.text_generic', ['count' => $count]),
        };
    }

    private function actionFor(string $type, array $params): string
    {
        if ($type === 'topic_gap') {
            return __('suggestions.action_topic_' . ($params['topic'] ?? 'other'));
        }
        return match ($type) {
            'unanswered_topic'     => __('suggestions.action_unanswered'),
            'missing_from_catalog' => __('suggestions.action_missing'),
            'compared_pair'        => __('suggestions.action_compared'),
            default                => __('suggestions.action_repeated_question'),
        };
    }

    /** Dismissed for good — the nightly rebuild deliberately leaves a
     *  dismissed row untouched rather than refreshing it. */
    public function dismiss(string $id): void
    {
        $tenant = auth()->user()->tenant;

        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        try {
            DB::table('suggestions')->where('id', $id)->update([
                'status' => 'dismissed',
                'dismissed_at' => now(),
            ]);
        } finally {
            DB::statement('SET search_path TO public');
        }

        Notification::make()->title(__('suggestions.dismissed'))->success()->send();
    }
}
