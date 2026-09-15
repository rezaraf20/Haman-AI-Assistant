<?php
namespace App\Filament\Customer\Pages;

use App\Services\SuggestionEngine;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * doc-07's "actions": the Trends page shows what happened, this one says
 * what to do about it. Every row is a rule firing on a real count, with
 * the real conversations behind it one click away — no LLM involved, so a
 * merchant can always be told exactly why they are seeing something.
 *
 * Reads only the suggestions table, written nightly by
 * haman:generate-suggestions.
 */
class Suggestions extends Page
{
    protected static string $view = 'filament.customer.pages.suggestions';
    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    public static function getNavigationLabel(): string { return __('suggestions.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('suggestions.nav'); }

    /**
     * A count badge is the honest way to say "you have things to act on",
     * but this runs on EVERY page of the panel and Filament asks more than
     * once per render. Computing it cost three statements a time and blew
     * the customer dashboard's query budget — caught by that page's own
     * guard, not by inspection.
     *
     * So it is strictly a READ of a value someone else wrote: the nightly
     * job publishes the count, dismiss() republishes it. A cold cache
     * simply shows no badge until the next run, which is a far better
     * trade than three queries on every page a merchant ever opens.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = auth()->user()?->tenant;
        if (!$tenant) return null;

        $count = (int) Cache::get(self::badgeCacheKey($tenant->schema_name), 0);
        return $count > 0 ? (string) $count : null;
    }

    public static function badgeCacheKey(string $schema): string
    {
        return "suggestions:badge:{$schema}";
    }

    /** Published by whoever just changed the underlying rows. */
    public static function publishBadgeCount(string $schema, int $count): void
    {
        // Comfortably longer than the daily rebuild interval, so the badge
        // survives until it is next republished rather than silently
        // vanishing mid-afternoon.
        Cache::put(self::badgeCacheKey($schema), $count, 60 * 60 * 30);
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
            'restock_requested' => __('suggestions.text_restock_requested', [
                'count' => $count, 'request' => $params['request'] ?? '',
            ]),
            'compared_pair' => __('suggestions.text_compared', [
                'count' => $count,
                // Names when the event carried them, ids only as a fallback.
                'products' => implode(' + ', ($params['names'] ?? null) ?: ($params['product_ids'] ?? [])),
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
            'restock_requested'    => __('suggestions.action_restock_requested'),
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
            $remaining = DB::table('suggestions')->where('status', 'active')->count();
        } finally {
            DB::statement('SET search_path TO public');
        }

        self::publishBadgeCount($tenant->schema_name, $remaining);

        Notification::make()->title(__('suggestions.dismissed'))->success()->send();
    }
}
