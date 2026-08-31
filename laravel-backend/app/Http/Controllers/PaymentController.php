<?php
namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller {
    public function __construct(private PaymentService $payments) {}

    // Deliberately unauthenticated — Zarinpal redirects the end user's browser
    // here with no session of ours. The transaction is looked up by the
    // Authority token, not trusted from any authenticated context.
    public function zarinpalCallback(Request $req): RedirectResponse {
        $authority = (string) $req->query('Authority', '');
        $status    = (string) $req->query('Status', '');

        $result = $authority
            ? $this->payments->handleCallback($authority, $status)
            : ['ok' => false, 'message' => 'Missing Authority'];

        return redirect()->to(
            '/portal/wallet?topup=' . ($result['ok'] ? 'success' : 'failed')
            . '&message=' . urlencode($result['message'])
        );
    }
}
