<?php
namespace App\Filament\Resources\CryptoTransferResource\Pages;

use App\Filament\Resources\CryptoTransferResource;
use App\Services\Crypto\{UsdtDeposits, UsdtScanner};
use App\Support\Jalali;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListCryptoTransfers extends ListRecords {
    protected static string $resource = CryptoTransferResource::class;

    /** The scanner's health in one line: is it on, when did it last run, how far behind the chain, any error. */
    public function getSubheading(): ?string {
        if (!UsdtDeposits::enabled()) {
            return __('usdt.scanner_off');
        }
        $cp = DB::table('chain_checkpoints')->where('name', UsdtScanner::CHECKPOINT)->first();
        if (!$cp || !$cp->heartbeat_at) {
            return __('usdt.scanner_never') . ($cp?->last_error ? ' — ' . $cp->last_error : '');
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
        return $line;
    }
}
