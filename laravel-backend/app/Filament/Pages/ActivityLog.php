<?php
namespace App\Filament\Pages;

use App\Models\{Tenant, User};
use App\Support\{PlatformAccess, PlatformActivity};
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * What platform users did, for the admin who has to answer for it.
 *
 * Read-only in the strongest sense the framework allows: this page exposes
 * no delete action, no bulk action, and no mutating method at all. The only
 * queries it issues are SELECTs. Nothing else in the application deletes
 * from platform_activity_log either — the retention command blanks two
 * columns with an UPDATE and leaves every row where it is.
 *
 * Admin-only. Support not being able to read this is deliberate: an
 * operator who can see exactly what is recorded about them can see exactly
 * what is not.
 */
class ActivityLog extends Page
{
    protected static string $view = 'filament.pages.activity-log';
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int $navigationSort = 9;

    private const PER_PAGE = 50;

    public ?string $userFilter = null;
    public ?string $tenantFilter = null;
    public ?string $actionFilter = null;
    public ?string $fromFilter = null;
    public ?string $toFilter = null;
    public int $page = 1;
    /** Row ids whose before/after panel is expanded. */
    public array $expanded = [];

    public static function canAccess(): bool { return PlatformAccess::allows('activity_log'); }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('activity_log'); }
    public static function getNavigationLabel(): string { return __('activity_log.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_infrastructure'); }
    public function getTitle(): string { return __('activity_log.nav'); }

    public function mount(): void
    {
        // Direct-route enforcement, not just a hidden menu item.
        abort_unless(static::canAccess(), 403);

        $this->fromFilter ??= now()->subDays(30)->toDateString();
    }

    /** Any filter change starts again from the first page. */
    public function updated(string $property): void
    {
        if (str_ends_with($property, 'Filter')) $this->page = 1;
    }

    public function toggleRow(string $id): void
    {
        $this->expanded = in_array($id, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$id]))
            : [...$this->expanded, $id];
    }

    public function isExpanded(string $id): bool
    {
        return in_array($id, $this->expanded, true);
    }

    public function resetFilters(): void
    {
        $this->userFilter = $this->tenantFilter = $this->actionFilter = $this->toFilter = null;
        $this->fromFilter = now()->subDays(30)->toDateString();
        $this->page = 1;
    }

    private function baseQuery()
    {
        $query = DB::table('platform_activity_log');

        if ($this->userFilter)   $query->where('user_id', $this->userFilter);
        if ($this->tenantFilter) $query->where('tenant_id', $this->tenantFilter);
        if ($this->actionFilter) $query->where('action', $this->actionFilter);
        if ($this->fromFilter)   $query->where('created_at', '>=', $this->fromFilter . ' 00:00:00');
        if ($this->toFilter)     $query->where('created_at', '<=', $this->toFilter . ' 23:59:59');

        return $query->orderByDesc('created_at');
    }

    public function getTotal(): int
    {
        return (clone $this->baseQuery())->count();
    }

    public function getLastPage(): int
    {
        return max(1, (int) ceil($this->getTotal() / self::PER_PAGE));
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->getLastPage()));
    }

    /**
     * One query for the page of rows, plus two small lookups to turn ids
     * into names. Tenant names are not joined in SQL because a tenant may
     * have been deleted since — the log outlives it, and the row must still
     * render.
     */
    public function getRows(): array
    {
        $rows = $this->baseQuery()
            ->offset((max(1, $this->page) - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get()
            ->all();

        $tenantIds = array_filter(array_unique(array_map(fn ($r) => $r->tenant_id, $rows)));
        $names = $tenantIds
            ? Tenant::whereIn('id', $tenantIds)->pluck('name', 'id')->all()
            : [];

        foreach ($rows as $row) {
            $row->tenant_name = $row->tenant_id ? ($names[$row->tenant_id] ?? __('activity_log.deleted_tenant')) : null;
            $row->before_data = $row->before ? (json_decode($row->before, true) ?: []) : [];
            $row->after_data  = $row->after ? (json_decode($row->after, true) ?: []) : [];
            $row->changed_keys = array_values(array_unique([
                ...array_keys($row->before_data),
                ...array_keys($row->after_data),
            ]));
        }

        return $rows;
    }

    /** Only staff who actually appear in the log, not every user account. */
    public function userOptions(): array
    {
        $ids = DB::table('platform_activity_log')->distinct()->pluck('user_id')->all();
        if (!$ids) return [];

        return User::whereIn('id', $ids)->orderBy('email')->pluck('email', 'id')->all();
    }

    public function tenantOptions(): array
    {
        $ids = DB::table('platform_activity_log')->whereNotNull('tenant_id')->distinct()->pluck('tenant_id')->all();
        if (!$ids) return [];

        return Tenant::whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Grouped so a long flat list of action names stays navigable. */
    public function actionOptions(): array
    {
        $options = [];
        foreach (PlatformActivity::ACTIONS as $group => $actions) {
            $label = __('activity_log.group_' . $group);
            foreach ($actions as $action) {
                $options[$label][$action] = $this->actionLabel($action);
            }
        }
        return $options;
    }

    public function actionLabel(string $action): string
    {
        $key = 'activity_log.action_' . $action;
        $translated = __($key);
        return $translated === $key ? $action : $translated;
    }

    public function reasonLabel(?string $reason): ?string
    {
        if (!$reason) return null;

        return __('staff_conversations.reason_' . match ($reason) {
            'error_report'     => 'error',
            'customer_request' => 'customer',
            default            => 'ticket',
        });
    }

    public function exportCsv()
    {
        abort_unless(static::canAccess(), 403);

        // Export honours the filters on screen: an admin asking what one
        // operator did last week wants that, not all 18 months.
        $rows = (clone $this->baseQuery())->limit(50000)->get();

        $header = ['created_at', 'user_email', 'platform_role', 'action', 'tenant_id',
                   'subject_type', 'subject_id', 'reason', 'before', 'after', 'ip', 'user_agent'];

        $csv = "\xEF\xBB\xBF" . implode(',', $header) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"',
                [$row->created_at, $row->user_email, $row->platform_role, $row->action,
                 $row->tenant_id, $row->subject_type, $row->subject_id, $row->reason,
                 $row->before, $row->after, $row->ip, $row->user_agent]
            )) . "\n";
        }

        return response()->streamDownload(
            fn () => print($csv),
            'platform-activity-' . now()->format('Y-m-d') . '.csv',
            ['Content-Type' => 'text/csv; charset=utf-8'],
        );
    }
}
