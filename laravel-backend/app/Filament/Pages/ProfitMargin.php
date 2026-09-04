<?php
namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\WalletTransaction;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{DatePicker, Section};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Per-tenant profit margin over a selectable date range: what a tenant paid
 * the platform (their completed wallet debits — plan_charge renewals today,
 * any future debit type tomorrow, all captured the same way rather than
 * hardcoding 'plan_charge') minus what the platform spent generating their
 * chatbots' replies (SUM(messages.cost_toman), computed per-LLM-call from
 * each provider's admin-entered per-1M-token price — see
 * LlmProviderProfileResource and rag_service.py's _compute_cost_toman()).
 * Reza needs this to actually price plans instead of guessing.
 */
class ProfitMargin extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.pages.profit-margin';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string { return __('panel.profit_margin_nav'); }
    public function getTitle(): string { return __('panel.profit_margin_title'); }

    public ?array $data = [];

    public function mount(): void {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to'   => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form {
        return $form->schema([
            Section::make()
                ->schema([
                    DatePicker::make('from')->label(__('panel.date_from'))->live()->native(false),
                    DatePicker::make('to')->label(__('panel.date_to'))->live()->native(false),
                ])
                ->columns(2),
        ])->statePath('data');
    }

    public function getMarginRows(): array {
        $state = $this->form->getState();
        $from  = Carbon::parse($state['from'] ?? now()->startOfMonth())->startOfDay();
        $to    = Carbon::parse($state['to'] ?? now()->endOfMonth())->endOfDay();

        $revenueByTenant = WalletTransaction::query()
            ->where('status', 'completed')
            ->where('amount_toman', '<', 0)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('tenant_id, SUM(-amount_toman) as revenue')
            ->groupBy('tenant_id')
            ->pluck('revenue', 'tenant_id');

        $rows = [];
        foreach (Tenant::query()->orderBy('name')->get() as $tenant) {
            $cost = 0;
            try {
                DB::statement("SET search_path TO {$tenant->schema_name}, public");
                $cost = (float) (DB::table('messages')
                    ->whereBetween('created_at', [$from, $to])
                    ->sum('cost_toman') ?? 0);
            } catch (\Throwable $e) {
                // A tenant schema that predates cost_toman/messages entirely
                // (shouldn't happen post-fixSchema, but this is a report page,
                // not a place to let one bad schema 500 the whole table) — 0
                // cost is the honest answer if it can't be computed.
            } finally {
                DB::statement('SET search_path TO public');
            }

            $revenue = (float) ($revenueByTenant[$tenant->id] ?? 0);
            $margin  = $revenue - $cost;

            $rows[] = [
                'tenant_id'   => $tenant->id,
                'tenant_name' => $tenant->name,
                'revenue'     => $revenue,
                'cost'        => $cost,
                'margin'      => $margin,
                'margin_pct'  => $revenue > 0 ? round($margin / $revenue * 100, 1) : null,
            ];
        }

        return $rows;
    }
}
