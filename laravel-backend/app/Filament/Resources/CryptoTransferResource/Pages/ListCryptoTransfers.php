<?php
namespace App\Filament\Resources\CryptoTransferResource\Pages;

use App\Filament\Resources\CryptoTransferResource;
use App\Services\Crypto\{UsdtDeposits, UsdtScanner};
use App\Support\Jalali;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListCryptoTransfers extends ListRecords {
    protected static string $resource = CryptoTransferResource::class;

    /**
     * The two numbers that say whether USDT payments work as a business, not
     * just as code: of the final transfers in the last 30 days, how many no
     * request matched (a customer who paid and was not credited
     * automatically), and how many transfers are set aside without proof
     * they are gone (should be zero — anything else is money not credited).
     */
    public static function metricsLine(): string
    {
        $since = now()->subDays(30);
        $final = \App\Models\Crypto\CryptoTransfer::where('created_at', '>=', $since)
            ->whereIn('status', ['credited', 'unmatched', 'stuck'])->selectRaw("count(*) AS total, count(*) FILTER (WHERE status = 'unmatched') AS unmatched, count(*) FILTER (WHERE status = 'credited' AND deposit_id IS NOT NULL) AS auto")
            ->first();
        $total = (int) $final->total;
        return __('usdt.metrics_line', [
            'total' => number_format($total),
            'auto' => number_format((int) $final->auto),
            'unmatched' => number_format((int) $final->unmatched),
            'pct' => $total > 0 ? number_format(100 * (int) $final->unmatched / $total, 1) : '0',
            'aside' => number_format(UsdtScanner::setAsideWithoutEvidence()),
        ]);
    }

    /** The scanner's health in one line: is it on, when did it last run, how far behind the chain, any error. */
    public function getSubheading(): ?string {
        if (!UsdtDeposits::enabled()) {
            return __('usdt.scanner_off') . "\n" . self::metricsLine();
        }
        $cp = DB::table('chain_checkpoints')->where('name', UsdtScanner::CHECKPOINT)->first();
        if (!$cp || !$cp->heartbeat_at) {
            return __('usdt.scanner_never') . ($cp?->last_error ? ' — ' . $cp->last_error : '') . "\n" . self::metricsLine();
        }
        $line = __('usdt.scanner_status', [
            'when' => Jalali::dateTime($cp->heartbeat_at),
            'block' => number_format((int) $cp->last_scanned_block),
            'lag' => number_format(max(0, (int) $cp->head_block - (int) $cp->last_scanned_block)),
            'provider' => $cp->provider ?? '—',
        ]);
        if ($cp->last_error && $cp->last_error_at && $cp->last_error_at > $cp->heartbeat_at) {
            $line .= ' — ' . __('usdt.scanner_error', ['error' => $cp->last_error]);
        }
        return $line . "\n" . self::metricsLine();
    }
}
