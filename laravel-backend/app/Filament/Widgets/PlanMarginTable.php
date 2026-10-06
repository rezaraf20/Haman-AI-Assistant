<?php
namespace App\Filament\Widgets;

use App\Support\PlatformAccess;

use App\Models\{LlmProviderProfile, Plan};
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * "Is this plan's price actually enough to cover what its quota costs to
 * serve?" — the question nobody could answer until a real per-1M-token
 * price was typed into an LlmProviderProfile for a plan's model_tier. Until
 * then every row here legitimately reads 0 cost / 100% margin, which is not
 * a bug — it is the honest "no data yet" state, not a real number to act on.
 *
 * Cost estimate: (plan's monthly token quota / 1,000,000) × the average of
 * that model's input and output per-1M price — a real query/response split
 * varies per conversation, so this is directional, not an invoice-grade
 * figure; good enough to catch a plan priced below what it could plausibly
 * cost, not precise enough to bill from.
 */
class PlanMarginTable extends Widget {
    public static function canView(): bool { return PlatformAccess::allows('pricing'); }

    protected static string $view = 'filament.widgets.plan-margin-table';
    protected int|string|array $columnSpan = 'full';

    public function getRows(): array {
        $profiles = LlmProviderProfile::all()->keyBy('model_name');

        return Plan::where('is_active', true)->orderBy('sort_order')->get()->map(function (Plan $plan) use ($profiles) {
            $profile = $profiles->get($plan->model_tier);
            $hasPrice = $profile && ((float) $profile->input_price_per_1m_toman > 0 || (float) $profile->output_price_per_1m_toman > 0);

            $avgPricePerMillion = $hasPrice
                ? ((float) $profile->input_price_per_1m_toman + (float) $profile->output_price_per_1m_toman) / 2
                : 0.0;
            $estimatedCost = ($plan->max_tokens_monthly / 1_000_000) * $avgPricePerMillion;
            $margin = $plan->price_monthly - $estimatedCost;
            $marginPercent = $plan->price_monthly > 0 ? ($margin / $plan->price_monthly) * 100 : null;

            return [
                'name'            => $plan->display_name,
                'price_monthly'   => $plan->price_monthly,
                'max_tokens'      => $plan->max_tokens_monthly,
                'model_tier'      => $plan->model_tier,
                'has_price_data'  => $hasPrice,
                'estimated_cost'  => $estimatedCost,
                'margin_toman'    => $margin,
                'margin_percent'  => $marginPercent,
                'is_negative'     => $plan->price_monthly > 0 && $margin < 0,
            ];
        })->all();
    }

    public function formatMoney(float $toman): string { return Money::toman((int) round($toman)); }
}
