<?php
namespace App\Services\Crypto;

use App\Models\Crypto\{CryptoDeposit, CryptoTransfer};
use App\Models\Tenant;
use App\Support\{Settings, Usdt};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Top-up requests for the USDT wallet.
 *
 * Every payment goes to the one address the platform owner set, so a
 * payment is told apart by its amount: each request gets the amount asked
 * for plus a unique tail of 1–9,999 micro-USDT (under one cent), e.g.
 * 20 → 20.004213. That exact amount, sent before the request expires, is
 * credited to that tenant automatically. An amount no request asked for
 * goes to the admin's review queue instead of being guessed at.
 */
class UsdtDeposits
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('crypto.usdt_enabled') && Usdt::isAddress(Settings::get('crypto.usdt_address'));
    }

    public static function address(): string
    {
        return strtolower((string) Settings::get('crypto.usdt_address'));
    }

    /** The tenant's open request, if any — one at a time, so the page always shows the one to pay. */
    public function open(Tenant $tenant): ?CryptoDeposit
    {
        return CryptoDeposit::where('tenant_id', $tenant->id)->where('status', 'pending')
            ->where('expires_at', '>', now())->latest()->first();
    }

    /**
     * @throws \DomainException disabled | amount_out_of_range
     */
    public function create(Tenant $tenant, int $requestedMicro, ?string $userId): CryptoDeposit
    {
        if (!self::enabled()) {
            throw new \DomainException('disabled');
        }
        $min = max(1, (int) Settings::get('crypto.usdt_min_deposit')) * Usdt::MICRO;
        $max = max(1, (int) Settings::get('crypto.usdt_max_deposit')) * Usdt::MICRO;
        if ($requestedMicro < $min || $requestedMicro > $max) {
            throw new \DomainException('amount_out_of_range');
        }

        $address = self::address();
        $ttl = max(5, (int) Settings::get('crypto.usdt_deposit_ttl_minutes'));
        $graceHours = max(0, (int) Settings::get('crypto.usdt_late_grace_hours'));

        // Cancelling the previous request and creating the new one are one
        // transaction: if no amount can be allocated (busy), the cancel rolls
        // back and the tenant keeps the request they had, instead of ending
        // up with none.
        return DB::transaction(function () use ($tenant, $requestedMicro, $userId, $address, $ttl, $graceHours) {
            CryptoDeposit::where('tenant_id', $tenant->id)->where('status', 'pending')->update(['status' => 'cancelled']);

            // Amounts still matchable — open, or expired/cancelled but within
            // the late-payment window — are not handed out again, so a late
            // payment can never match two requests.
            $taken = CryptoDeposit::where('address', $address)
                ->where('expires_at', '>', now()->subHours($graceHours))
                ->whereBetween('amount_micro', [$requestedMicro, $requestedMicro + 9999])
                ->pluck('amount_micro')->map(fn ($a) => (int) $a)->all();
            $free = array_values(array_diff(range($requestedMicro + 1, $requestedMicro + 9999), $taken));

            for ($attempt = 0; $attempt < 5 && $free; $attempt++) {
                $amount = $free[random_int(0, count($free) - 1)];
                try {
                    // Its own savepoint: in Postgres a unique violation aborts
                    // the whole transaction otherwise, and the retry could
                    // never run.
                    return DB::transaction(fn () => CryptoDeposit::create([
                        'tenant_id' => $tenant->id, 'created_by' => $userId,
                        'chain_id' => Usdt::CHAIN_ID, 'token_contract' => Usdt::CONTRACT, 'address' => $address,
                        'requested_micro' => $requestedMicro, 'amount_micro' => $amount,
                        'status' => 'pending', 'expires_at' => now()->addMinutes($ttl),
                    ]));
                } catch (UniqueConstraintViolationException) {
                    $free = array_values(array_diff($free, [$amount]));   // taken by a concurrent request
                }
            }
            throw new \DomainException('busy');   // rolls the cancel back too
        });
    }

    /**
     * What still ties the platform to the current receiving address. The
     * scanner reads only the current address's logs and matches on it, so
     * changing it while any of these exist means a payment that arrives at
     * the old wallet is never seen: no row, no credit, no trace.
     *
     *  - requests still matchable: pending, or expired/cancelled but inside
     *    the late-payment grace window (match() still accepts those);
     *  - transfers seen on chain but not settled yet (detected, or stuck in
     *    verification).
     *
     * @return array{requests:int, transfers:int, free_at:?\Illuminate\Support\Carbon}
     */
    public function addressChangeBlockers(): array
    {
        $address = self::address();
        if (!Usdt::isAddress($address)) {
            return ['requests' => 0, 'transfers' => 0, 'free_at' => null];
        }
        $grace = max(0, (int) Settings::get('crypto.usdt_late_grace_hours'));
        $requests = CryptoDeposit::where('address', $address)
            ->whereIn('status', ['pending', 'expired', 'cancelled'])
            ->where('expires_at', '>', now()->subHours($grace));
        $lastExpiry = (clone $requests)->max('expires_at');

        return [
            'requests' => $requests->count(),
            'transfers' => CryptoTransfer::where('to_address', $address)->whereIn('status', ['detected', 'stuck'])->count(),
            'free_at' => $lastExpiry ? \Illuminate\Support\Carbon::parse($lastExpiry)->addHours($grace) : null,
        ];
    }

    public function cancel(CryptoDeposit $deposit): void
    {
        CryptoDeposit::where('id', $deposit->id)->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    public function expireOld(): int
    {
        return CryptoDeposit::where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    /** A transfer for this request seen on chain but not final yet — what the page shows as "detected". */
    public function pendingTransfer(CryptoDeposit $deposit): ?CryptoTransfer
    {
        return CryptoTransfer::where('to_address', $deposit->address)->where('status', 'detected')
            ->where('amount_base', Usdt::microToBase((int) $deposit->amount_micro))
            ->where('created_at', '>=', $deposit->created_at->copy()->subMinutes(5))
            ->latest()->first();
    }
}
