<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * The attack surface, as a table anyone can regenerate.
 *
 * Written as a command rather than a document because a document describing
 * which endpoints are capped is out of date the first time somebody adds an
 * endpoint. This reads the live route table instead.
 *
 * Cost cannot be inferred from a route — only a person knows that
 * order-status/request-code spends the merchant's money — so every public
 * route must be declared in COSTS below. An undeclared one is reported as a
 * gap, which means adding an endpoint forces a decision about what it costs
 * rather than letting it default to "probably nothing".
 *
 * --fail-on-gap makes it usable from CI.
 */
class AbuseAuditCommand extends Command
{
    protected $signature = 'hamman:abuse-audit
                            {--fail-on-gap : Exit non-zero if anything that costs money is uncapped or unclassified}
                            {--all : Include authenticated routes too}';

    protected $description = 'List every endpoint with who can call it, whether it is rate limited, and what it costs';

    /**
     * What each publicly reachable endpoint actually spends.
     *
     * sms       — sends a text message, billed to a merchant or the platform
     * llm       — a model call, billed in tokens
     * embedding — vectorises text, billed per token
     * outbound  — a server-to-server HTTP call to a third party
     * db-write  — creates durable state (a schema, a row) but no direct bill
     * none      — reads or cheap writes
     */
    private const COSTS = [
        'api/v1/auth/register'                        => ['db-write', 'Creates a tenant AND a Postgres schema full of tables'],
        'api/v1/auth/login'                           => ['none', 'Password check; brute-force target rather than a cost'],
        'api/v1/wp-plugin/latest-version'             => ['none', 'Static version string'],
        'api/v1/chat/session'                         => ['db-write', 'Opens a conversation row'],
        'api/v1/chat/message'                         => ['llm', 'The main model call; also embeds the query'],
        'api/v1/chat/history/{sessionId}'             => ['none', 'Read'],
        'api/v1/chat/conversation/{conversationId}/messages' => ['none', 'Read'],
        'api/v1/chat/feedback'                        => ['none', 'Single row write'],
        'api/v1/chat/cart-event'                      => ['none', 'Single row write'],
        'api/v1/chat/payment-link'                    => ['outbound', 'Live query to the merchant shop'],
        'api/v1/chat/order-status/request-code'       => ['sms', "Sends an OTP, billed to the MERCHANT's wallet"],
        'api/v1/chat/order-status/verify'             => ['outbound', 'Live query to the merchant shop'],
        'payments/zarinpal/callback'                  => ['outbound', 'Server-to-server verify call to Zarinpal'],
        '/'                                           => ['none', 'Public landing page; reads published plans'],
        'signup'                                      => ['db-write', 'Creates a tenant AND a Postgres schema, same as the API register'],
        'verify-email/{id}/{hash}'                    => ['none', 'Signed, expiring link; marks one address verified'],
        'verify-email/resend'                         => ['none', 'Sends one email to the signed-in user own address'],
        'portal/login'                                => ['none', 'Renders the form; both Livewire actions behind it are capped (OtpLogin, EmailLogin)'],
        // Filament rate-limits the attempt itself inside the Livewire
        // action (Login::authenticate() calls rateLimit(5)), so the GET
        // being open is the form, not the guessing.
        'admin/login'                                 => ['none', 'Form only; Filament caps the attempt at 5/min in the Livewire action'],
        'filament/exports/{export}/download'          => ['none', 'Signed download of the requester own export'],
        'filament/imports/{import}/failed-rows/download' => ['none', 'Signed download'],
    ];

    /** Cost classes that must never be reachable without a cap. */
    private const MUST_BE_CAPPED = ['sms', 'llm', 'embedding', 'outbound', 'db-write'];

    public function handle(): int
    {
        $rows = [];
        $gaps = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if ($this->isFramework($uri)) continue;

            $middleware = $route->gatherMiddleware();
            $short = array_map(fn ($m) => is_string($m) ? class_basename($m) : 'closure', $middleware);

            $caller = $this->caller($short);
            if ($caller !== 'anyone' && !$this->option('all')) continue;

            $throttle = $this->throttle($short);
            [$cost, $note] = self::COSTS[$uri] ?? [null, null];

            if ($caller === 'anyone' && $cost === null) {
                $gaps[] = "{$uri} is publicly reachable but not classified in AbuseAuditCommand::COSTS";
                $cost = 'UNCLASSIFIED';
                $note = 'Declare what this costs';
            }

            $capped = $throttle !== null;
            if ($caller === 'anyone' && in_array($cost, self::MUST_BE_CAPPED, true) && !$capped) {
                $gaps[] = "{$uri} costs [{$cost}] and has no rate limit";
            }

            $rows[] = [
                implode('|', $route->methods()) === 'GET|HEAD' ? 'GET' : implode('|', array_diff($route->methods(), ['HEAD'])),
                '/' . $uri,
                $caller,
                $throttle ?? '— none —',
                $cost,
                $note ?? '',
            ];
        }

        usort($rows, fn ($a, $b) => [$a[4], $a[1]] <=> [$b[4], $b[1]]);

        $this->table(['Method', 'Endpoint', 'Who can call it', 'Rate limit', 'Cost', 'Notes'], $rows);

        $this->newLine();
        if ($gaps) {
            $this->error(count($gaps) . ' gap(s):');
            foreach ($gaps as $gap) $this->line('  - ' . $gap);

            return $this->option('fail-on-gap') ? self::FAILURE : self::SUCCESS;
        }

        $this->info('No uncapped cost-producing endpoint, and nothing unclassified.');

        return self::SUCCESS;
    }

    private function isFramework(string $uri): bool
    {
        foreach (['_ignition', 'sanctum/', 'livewire/', 'storage/', 'up', '_debugbar'] as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix)) return true;
        }
        return false;
    }

    /** @param string[] $middleware */
    private function caller(array $middleware): string
    {
        if (in_array('AuthenticateTenantApiKey', $middleware, true)) return 'plugin (API key)';
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'auth:sanctum')) return 'customer (token)';
            if ($m === 'Authenticate' || str_starts_with($m, 'auth')) return 'signed-in session';
        }
        return 'anyone';
    }

    /** @param string[] $middleware */
    private function throttle(array $middleware): ?string
    {
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'throttle:')) return substr($m, strlen('throttle:'));
            if (str_starts_with($m, 'ThrottleRequests:')) return substr($m, strlen('ThrottleRequests:'));
        }
        return null;
    }
}
