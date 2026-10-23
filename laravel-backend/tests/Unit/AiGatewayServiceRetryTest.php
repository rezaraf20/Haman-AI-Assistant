<?php
namespace Tests\Unit;

use App\Services\AiGatewayService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A real incident (2026-10-22): the default ->retry() on AiGatewayService::
 * post() fires on ANY exception, including the request's own timeout — so a
 * genuinely slow (but still working) python_ai call got retried mid-flight,
 * running the whole pipeline (including a write-tool call) a second time
 * while the first attempt was still executing server-side. The fix is the
 * `when` callback: retry a real connection failure, never a timeout.
 */
class AiGatewayServiceRetryTest extends TestCase
{
    // Http::fake()'s own request bookkeeping only records a request once
    // its stub RETURNS a response — a stub that throws never reaches that
    // point, so Http::assertSentCount() always reads 0 regardless of how
    // many times the stub actually ran. Counting invocations directly is
    // the only reliable way to assert attempt counts for this scenario.
    private function throwing(string $curlMessage, array &$attempts): \Closure
    {
        return function () use ($curlMessage, &$attempts) {
            $attempts[] = $curlMessage;
            throw new ConnectionException($curlMessage);
        };
    }

    public function test_a_timeout_is_never_retried(): void
    {
        $attempts = [];
        Http::fake($this->throwing('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received', $attempts));

        try {
            (new AiGatewayService())->chat(['query' => 'hello']);
            $this->fail('Expected an exception.');
        } catch (\Throwable $e) {
            // AiServiceException wraps it — the specific type isn't the
            // point here, the attempt count is.
        }

        $this->assertCount(1, $attempts, 'a timeout must never be retried');
    }

    public function test_a_connection_refusal_is_retried(): void
    {
        $attempts = [];
        Http::fake($this->throwing('cURL error 7: Failed to connect to python_ai port 8001: Connection refused', $attempts));

        try {
            (new AiGatewayService())->chat(['query' => 'hello']);
            $this->fail('Expected an exception.');
        } catch (\Throwable $e) {
        }

        // Laravel's retry($times, ...) makes at most $times TOTAL attempts,
        // not $times retries beyond the first.
        $this->assertCount(config('haman.ai_service.retry', 2), $attempts);
    }

    public function test_a_dns_failure_is_retried(): void
    {
        $attempts = [];
        Http::fake($this->throwing('cURL error 6: Could not resolve host: python_ai', $attempts));

        try {
            (new AiGatewayService())->chat(['query' => 'hello']);
            $this->fail('Expected an exception.');
        } catch (\Throwable $e) {
        }

        $this->assertCount(config('haman.ai_service.retry', 2), $attempts);
    }

    public function test_a_successful_call_is_never_retried(): void
    {
        Http::fake(['*' => Http::response(['response' => 'ok'], 200)]);

        $result = (new AiGatewayService())->chat(['query' => 'hello']);

        $this->assertSame('ok', $result['response']);
        Http::assertSentCount(1);
    }
}
