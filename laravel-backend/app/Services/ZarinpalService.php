<?php
namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;

class ZarinpalService {
    private string $apiBase;
    private string $startPayBase;
    private string $merchantId;

    // Reads from the DB (editable at app/Filament/Pages/Settings.php), not
    // .env — Reza needs to be able to change the merchant ID / sandbox toggle
    // from the admin panel without asking for a code change + redeploy.
    public function __construct() {
        $settings = PlatformSetting::current();
        $sandbox  = $settings->zarinpal_sandbox;
        $this->apiBase      = $sandbox ? 'https://sandbox.zarinpal.com/pg/v4/payment' : 'https://api.zarinpal.com/pg/v4/payment';
        $this->startPayBase = $sandbox ? 'https://sandbox.zarinpal.com/pg/StartPay'   : 'https://www.zarinpal.com/pg/StartPay';
        $this->merchantId   = (string) $settings->zarinpal_merchant_id;
    }

    /**
     * @return array{ok:bool, authority?:string, redirect_url?:string, message?:string}
     */
    public function requestPayment(int $amountToman, string $callbackUrl, string $description): array {
        $resp = Http::timeout(20)->post("{$this->apiBase}/request.json", [
            'merchant_id'  => $this->merchantId,
            // Zarinpal's Amount is in Rial, not Toman — this ×10 is the single
            // most common integration bug for this gateway. Do not "simplify"
            // this away.
            'amount'       => $amountToman * 10,
            'callback_url' => $callbackUrl,
            'description'  => $description,
        ]);
        $body = $resp->json();
        $code = $body['data']['code'] ?? null;

        if ($code !== 100) {
            return ['ok' => false, 'message' => $body['errors']['message'] ?? $body['data']['message'] ?? 'Zarinpal request failed'];
        }
        $authority = $body['data']['authority'];
        return [
            'ok'           => true,
            'authority'    => $authority,
            'redirect_url' => "{$this->startPayBase}/{$authority}",
        ];
    }

    /**
     * @return array{ok:bool, ref_id?:string, message?:string}
     */
    public function verifyPayment(int $amountToman, string $authority): array {
        $resp = Http::timeout(20)->post("{$this->apiBase}/verify.json", [
            'merchant_id' => $this->merchantId,
            'amount'      => $amountToman * 10, // Rial, matching requestPayment.
            'authority'   => $authority,
        ]);
        $body = $resp->json();
        $code = $body['data']['code'] ?? null;

        // 100 = verified now, 101 = already verified (treat as success —
        // idempotency at the Zarinpal level, on top of our own DB-level check).
        if ($code !== 100 && $code !== 101) {
            return ['ok' => false, 'message' => $body['errors']['message'] ?? $body['data']['message'] ?? 'Zarinpal verify failed'];
        }
        return ['ok' => true, 'ref_id' => (string) ($body['data']['ref_id'] ?? '')];
    }
}
