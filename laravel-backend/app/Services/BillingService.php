<?php
namespace App\Services;

use App\Exceptions\{InsufficientBalance, InvoiceNotOpen};
use App\Mail\BillingNotice;
use App\Models\{ChatbotIndexEntry, Invoice, Plan, Tenant, User};
use App\Support\{Jalali, MailSettings, Money, Settings};
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Log, Mail};

/**
 * Plan changes and renewals: the part of billing that was missing.
 *
 * Until this existed a paid plan was charged once, the prorated difference
 * at upgrade, and then never again. Now:
 *
 *  - upgradePlan(): charges the prorated difference for the rest of the
 *    current period and records that the plan is paid until the period end
 *    (tenants.plan_paid_until).
 *  - run(), every hour from the scheduler:
 *      issue  — an invoice for the next period, `limits.billing_invoice_lead_days`
 *               ahead of plan_paid_until (or a paid chatbot's expires_at);
 *      charge — on the due date, from the wallet, automatically;
 *      expire — still unpaid `limits.billing_grace_days` after the due date:
 *               the invoice is voided and the plan drops to free (a chatbot
 *               is then suspended by chatbots:expire-overdue as before).
 *  - payInvoice(): the customer paying an open invoice by hand, after a
 *    top-up, with the same effect as the automatic charge.
 *
 * Every charge goes through WalletService::debit(), so the balance check,
 * the ledger row and the thing being paid for (a new paid-until date, a new
 * expiry) are one transaction: never charged without the renewal, never
 * renewed without the charge. Lock order is always tenant, then invoice.
 *
 * A tenant with plan_paid_until NULL is not billed. That is every tenant on
 * a free or trial plan, and every tenant an admin put on a paid plan before
 * this existed — billing starts when they upgrade themselves or an admin
 * sets the date (EditTenant records that change).
 */
class BillingService
{
    public function __construct(
        private WalletService $wallet,
        private TenantService $tenants,
    ) {}

    // ── Plan changes ───────────────────────────────────────────────────

    /** End of the period the tenant is in now: the paid-until date if there is one, otherwise their monthly quota cycle. */
    public function currentPeriodEnd(Tenant $tenant): CarbonInterface
    {
        if ($tenant->plan_paid_until && $tenant->plan_paid_until->isFuture()) {
            return $tenant->plan_paid_until->copy();
        }
        $end = ($tenant->quota_period_started_at ?? $tenant->created_at ?? now())->copy()->addMonthNoOverflow();
        while ($end->lessThanOrEqualTo(now())) {
            $end = $end->addMonthNoOverflow();
        }
        return $end;
    }

    /** Price difference for what is left of the current period, in whole Toman, integer arithmetic only. Never negative. */
    public function prorate(Tenant $tenant, Plan $current, Plan $target): int
    {
        $diff = (int) $target->price_monthly - (int) $current->price_monthly;
        if ($diff <= 0) return 0;

        $end = $this->currentPeriodEnd($tenant);
        $start = $end->copy()->subMonthNoOverflow();
        $total = max(1, $start->diffInSeconds($end, true));
        $left = (int) max(0, min($total, now()->diffInSeconds($end, false)));

        return intdiv($diff * $left, (int) $total);
    }

    public function isUpgrade(Plan $current, Plan $target): bool
    {
        return (int) $target->price_monthly > (int) $current->price_monthly;
    }

    /**
     * @return int the amount charged
     * @throws InsufficientBalance
     * @throws \DomainException when the target is not a plan the customer can pick, or not an upgrade
     */
    public function upgradePlan(Tenant $tenant, Plan $target): int
    {
        if (!$target->is_active || !$target->is_public) {
            throw new \DomainException('plan_not_available');
        }
        $current = Plan::findOrFail($tenant->plan_id);
        if (!$this->isUpgrade($current, $target)) {
            throw new \DomainException('not_an_upgrade');
        }

        $charge = $this->prorate($tenant, $current, $target);
        $periodEnd = $this->currentPeriodEnd($tenant);

        $grant = function (Tenant $locked) use ($target, $periodEnd) {
            // An upgrade supersedes any scheduled downgrade, and is paid
            // from now to the end of this period.
            $locked->update([
                'plan_id' => $target->id,
                'pending_plan_id' => null,
                'pending_plan_effective_at' => null,
                'plan_paid_until' => $periodEnd,
            ]);
            // An invoice already issued for the next period is at the old
            // price — void it; the next run issues one at the new price.
            $this->voidOpenPlanInvoices($locked, 'plan_changed');
        };

        if ($charge > 0) {
            $this->wallet->debit($tenant, $charge, 'plan_charge', [
                'description' => __('plan.upgrade_charge_description', ['name' => $target->display_name]),
                'metadata' => ['plan_id' => $target->id, 'period_end' => $periodEnd->toIso8601String()],
            ], $grant);
        } else {
            DB::transaction(fn () => $grant(Tenant::where('id', $tenant->id)->lockForUpdate()->firstOrFail()));
        }

        return $charge;
    }

    /** A downgrade never applies mid-period: it is scheduled for the end of what has been paid for. */
    public function scheduleDowngrade(Tenant $tenant, Plan $target): CarbonInterface
    {
        if (!$target->is_active || !$target->is_public) {
            throw new \DomainException('plan_not_available');
        }
        $effectiveAt = $this->currentPeriodEnd($tenant);

        DB::transaction(function () use ($tenant, $target, $effectiveAt) {
            $locked = Tenant::where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            $locked->update(['pending_plan_id' => $target->id, 'pending_plan_effective_at' => $effectiveAt]);
            $this->voidOpenPlanInvoices($locked, 'plan_changed');
        });

        return $effectiveAt;
    }

    private function voidOpenPlanInvoices(Tenant $tenant, string $reason): void
    {
        Invoice::where('tenant_id', $tenant->id)->where('purpose', Invoice::PLAN)->where('status', 'open')
            ->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason]);
    }

    // ── Renewals ───────────────────────────────────────────────────────

    /** @return array{issued:int, paid:int, unpaid:int, expired:int} */
    public function run(): array
    {
        $issued = $this->issuePlanInvoices() + $this->issueChatbotInvoices();
        [$paid, $unpaid] = $this->chargeDue();
        $expired = $this->expireUnpaid();

        return compact('issued', 'paid', 'unpaid', 'expired');
    }

    private function leadDays(): int { return max(0, (int) Settings::get('limits.billing_invoice_lead_days')); }
    private function graceDays(): int { return max(0, (int) Settings::get('limits.billing_grace_days')); }

    /** Only a tenant that is using the platform gets billed — not a suspended, cancelled or to-be-deleted one. */
    private function billableTenants()
    {
        return Tenant::whereIn('status', ['active', 'trial'])->whereNull('pending_deletion_at');
    }

    public function issuePlanInvoices(): int
    {
        $count = 0;
        $tenants = $this->billableTenants()
            ->with('plan')
            ->whereNotNull('plan_paid_until')
            ->where('plan_paid_until', '<=', now()->addDays($this->leadDays()))
            ->get();

        foreach ($tenants as $tenant) {
            $start = $tenant->plan_paid_until->copy();
            // The plan the next period will be on: a downgrade scheduled for
            // that date is billed at its own price; one to free is not billed.
            $plan = ($tenant->pending_plan_id && $tenant->pending_plan_effective_at && $tenant->pending_plan_effective_at->lessThanOrEqualTo($start))
                ? Plan::find($tenant->pending_plan_id)
                : $tenant->plan;
            if (!$plan || (int) $plan->price_monthly <= 0) continue;

            $invoice = $this->issue($tenant, Invoice::PLAN, (string) $tenant->id, $plan->id, $plan->display_name,
                (int) $plan->price_monthly, $start, $start->copy()->addMonthNoOverflow());
            if ($invoice) $count++;
        }
        return $count;
    }

    public function issueChatbotInvoices(): int
    {
        $count = 0;
        $entries = ChatbotIndexEntry::query()
            ->where('monthly_price_toman', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($this->leadDays()))
            // A chatbot that lapsed long ago is not billed back into life.
            ->where('expires_at', '>=', now()->subDays($this->graceDays()))
            ->whereIn('tenant_id', $this->billableTenants()->select('id'))
            ->get();

        foreach ($entries as $entry) {
            $tenant = Tenant::find($entry->tenant_id);
            $start = $entry->expires_at->copy();
            $invoice = $this->issue($tenant, Invoice::CHATBOT, (string) $entry->chatbot_id, null, (string) $entry->name,
                (int) $entry->monthly_price_toman, $start, $start->copy()->addMonthNoOverflow());
            if ($invoice) $count++;
        }
        return $count;
    }

    /** Idempotent: the partial unique index allows one live invoice per subject per period, so a second run issues nothing. */
    private function issue(Tenant $tenant, string $purpose, string $subjectId, ?string $planId, string $description,
                           int $amount, CarbonInterface $start, CarbonInterface $end): ?Invoice
    {
        $exists = Invoice::where('purpose', $purpose)->where('subject_id', $subjectId)
            ->where('period_start', $start)->where('status', '!=', 'void')->exists();
        if ($exists) return null;

        try {
            $invoice = Invoice::create([
                'tenant_id' => $tenant->id, 'purpose' => $purpose, 'subject_id' => $subjectId, 'plan_id' => $planId,
                'description' => $description, 'currency' => 'IRT', 'amount' => $amount,
                'period_start' => $start, 'period_end' => $end, 'status' => 'open',
                'issued_at' => now(), 'due_at' => $start,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // a concurrent run issued it first
        }

        $this->notify($tenant, 'issued', $invoice);
        return $invoice;
    }

    /** @return array{0:int,1:int} [paid, still unpaid] */
    public function chargeDue(): array
    {
        $paid = $unpaid = 0;
        $due = Invoice::where('status', 'open')->where('due_at', '<=', now())->orderBy('due_at')->get();

        foreach ($due as $invoice) {
            try {
                $this->settle($invoice);
                $paid++;
            } catch (InsufficientBalance) {
                $unpaid++;
                $attempts = $invoice->attempts + 1;
                Invoice::where('id', $invoice->id)->where('status', 'open')->update([
                    'attempts' => $attempts, 'last_attempt_at' => now(), 'last_failure' => 'insufficient_balance',
                ]);
                // Told once, on the first failed attempt — the hourly retry
                // must not become an hourly email.
                if ($attempts === 1) $this->notify(Tenant::find($invoice->tenant_id), 'unpaid', $invoice);
            } catch (InvoiceNotOpen) {
                // Paid by hand, or voided, between the query and now.
            }
        }
        return [$paid, $unpaid];
    }

    /**
     * The customer paying an open invoice from the portal.
     *
     * @throws InsufficientBalance
     * @throws InvoiceNotOpen
     */
    public function payInvoice(Invoice $invoice): void
    {
        $this->settle($invoice);
    }

    private function settle(Invoice $invoice): void
    {
        $this->wallet->debit($invoice->tenant_id, (int) $invoice->amount, 'plan_charge', [
            'description' => __('billing.charge_description', ['number' => $invoice->number, 'name' => $invoice->description]),
            'metadata' => ['invoice_id' => $invoice->id],
        ], function (Tenant $tenant, $txn) use ($invoice) {
            $locked = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw new InvoiceNotOpen(); // rolls the debit back
            }
            $locked->update(['status' => 'paid', 'paid_at' => now(), 'wallet_transaction_id' => $txn->id,
                'attempts' => $locked->attempts + 1, 'last_attempt_at' => now(), 'last_failure' => null]);

            if ($locked->purpose === Invoice::PLAN) {
                $this->extendPlan($tenant, $locked);
            } else {
                $this->extendChatbot($locked);
            }
        });

        $this->notify(Tenant::find($invoice->tenant_id), 'paid', $invoice->fresh());
    }

    private function extendPlan(Tenant $tenant, Invoice $invoice): void
    {
        $updates = [];
        if (!$tenant->plan_paid_until || $tenant->plan_paid_until->lessThan($invoice->period_end)) {
            $updates['plan_paid_until'] = $invoice->period_end;
        }
        // The period was billed at a scheduled downgrade's price: switch now
        // rather than leave the dearer plan running until the daily
        // transition job notices.
        if ($tenant->pending_plan_id && $tenant->pending_plan_id === $invoice->plan_id
            && $tenant->pending_plan_effective_at && $tenant->pending_plan_effective_at->lessThanOrEqualTo($invoice->period_start)) {
            $updates += ['plan_id' => $invoice->plan_id, 'pending_plan_id' => null, 'pending_plan_effective_at' => null];
        }
        if ($updates) $tenant->update($updates);
    }

    private function extendChatbot(Invoice $invoice): void
    {
        $entry = ChatbotIndexEntry::where('chatbot_id', $invoice->subject_id)->lockForUpdate()->first();
        if (!$entry) return;

        if (!$entry->expires_at || $entry->expires_at->lessThan($invoice->period_end)) {
            ChatbotIndexEntry::where('chatbot_id', $entry->chatbot_id)->update(['expires_at' => $invoice->period_end]);
        }
        // Back on only if it was off for having expired — a chatbot that is
        // off for any other reason (no domain yet, an admin's decision)
        // stays off; setChatbotActive() also keeps the tenant-schema row in
        // step, which a bare chatbot_index update did not.
        if (!$entry->is_active && $entry->disabled_reason === 'expired') {
            $this->tenants->setChatbotActive($entry->chatbot_id, true);
        }
    }

    /** Unpaid past the grace period: void, and a plan drops to free. */
    public function expireUnpaid(): int
    {
        $count = 0;
        $overdue = Invoice::where('status', 'open')->where('due_at', '<', now()->subDays($this->graceDays()))->get();
        $freePlanId = Plan::where('slug', 'free')->value('id');

        foreach ($overdue as $invoice) {
            $voided = DB::transaction(function () use ($invoice, $freePlanId) {
                $tenant = Tenant::where('id', $invoice->tenant_id)->lockForUpdate()->first();
                $locked = Invoice::where('id', $invoice->id)->lockForUpdate()->first();
                if (!$locked || $locked->status !== 'open') return false;

                $locked->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => 'unpaid']);

                // Only if nothing else has paid this period meanwhile (an
                // admin extending the date by hand, say).
                if ($locked->purpose === Invoice::PLAN && $tenant && $freePlanId
                    && (!$tenant->plan_paid_until || $tenant->plan_paid_until->lessThanOrEqualTo($locked->period_start))) {
                    $tenant->update(['plan_id' => $freePlanId, 'plan_paid_until' => null,
                        'pending_plan_id' => null, 'pending_plan_effective_at' => null]);
                }
                return true;
            });

            if ($voided) {
                $count++;
                $this->notify(Tenant::find($invoice->tenant_id), 'expired', $invoice->fresh());
            }
        }
        return $count;
    }

    /** True while a chatbot's renewal invoice is open and still inside its grace period — chatbots:expire-overdue leaves it running meanwhile. */
    public static function inGrace(string $chatbotId): bool
    {
        return Invoice::where('purpose', Invoice::CHATBOT)->where('subject_id', $chatbotId)->where('status', 'open')
            ->where('due_at', '>=', now()->subDays(max(0, (int) Settings::get('limits.billing_grace_days'))))
            ->exists();
    }

    // ── Notices ────────────────────────────────────────────────────────

    /** In-app to every user of the tenant, plus email when SMTP is set up — in the tenant's own language. */
    private function notify(?Tenant $tenant, string $kind, Invoice $invoice): void
    {
        if (!$tenant) return;
        $previous = app()->getLocale();
        app()->setLocale(in_array($tenant->language, ['fa', 'en'], true) ? $tenant->language : 'fa');

        try {
            $params = [
                'number' => $invoice->number,
                'name'   => $invoice->description,
                'amount' => Money::toman((int) $invoice->amount),
                'date'   => Jalali::date($invoice->due_at) ?? $invoice->due_at->toDateString(),
                'grace'  => $this->graceDays(),
            ];
            $subject = __("billing.notice_{$kind}_subject", $params);
            $body = __("billing.notice_{$kind}_body", $params);

            foreach (User::where('tenant_id', $tenant->id)->get() as $user) {
                Notification::make()->title($subject)->body($body)
                    ->{$kind === 'paid' ? 'success' : 'warning'}()->sendToDatabase($user);
            }
            if (MailSettings::isUsable() && $tenant->email) {
                try {
                    Mail::to($tenant->email)->send(new BillingNotice($subject, $body));
                } catch (\Throwable $e) {
                    Log::warning("BillingService: email to {$tenant->email} failed — {$e->getMessage()}");
                }
            }
        } finally {
            app()->setLocale($previous);
        }
    }
}
