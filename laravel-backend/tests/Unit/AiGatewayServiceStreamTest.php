<?php
namespace Tests\Unit;

use App\Services\AiGatewayService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * chatStream()'s $onInterim callback — a real, server-decided "I'm
 * checking products now"-type placeholder python_ai sends right after
 * intent classification, before the genuinely slow part of a tool-calling
 * turn even starts (see rag_service.run_rag_pipeline_stream()). This is
 * the one-line-of-experience fix for a turn that can otherwise take
 * 20-40s with nothing visible but bouncing dots — see the 2026-10-23
 * investigation.
 */
class AiGatewayServiceStreamTest extends TestCase
{
    private function sse(array $frames): string
    {
        return implode('', array_map(fn ($f) => $f . "\n\n", $frames));
    }

    public function test_an_interim_event_calls_onInterim_with_the_real_text(): void
    {
        $body = $this->sse([
            "event: interim\ndata: " . json_encode(['text' => 'در حال بررسی محصولات...']),
            'data: ' . json_encode(['delta' => 'answer']),
            "event: done\ndata: " . json_encode(['response' => 'answer', 'widget_blocks' => []]),
        ]);
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

        $interims = [];
        $deltas = [];

        $result = (new AiGatewayService())->chatStream(
            ['query' => 'hello'],
            function (string $d) use (&$deltas) { $deltas[] = $d; },
            function (string $t) use (&$interims) { $interims[] = $t; },
        );

        $this->assertSame(['در حال بررسی محصولات...'], $interims);
        $this->assertSame(['answer'], $deltas);
        $this->assertSame('answer', $result['response']);
    }

    public function test_no_onInterim_callback_is_fine_an_interim_event_is_just_ignored(): void
    {
        $body = $this->sse([
            "event: interim\ndata: " . json_encode(['text' => 'checking...']),
            'data: ' . json_encode(['delta' => 'answer']),
            "event: done\ndata: " . json_encode(['response' => 'answer']),
        ]);
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

        $result = (new AiGatewayService())->chatStream(
            ['query' => 'hello'],
            function (string $d) {},
        );

        $this->assertSame('answer', $result['response']);
    }

    public function test_a_stream_with_no_interim_event_at_all_behaves_exactly_as_before(): void
    {
        $body = $this->sse([
            'data: ' . json_encode(['delta' => 'hi']),
            "event: done\ndata: " . json_encode(['response' => 'hi']),
        ]);
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

        $interims = [];
        $deltas = [];

        $result = (new AiGatewayService())->chatStream(
            ['query' => 'hello'],
            function (string $d) use (&$deltas) { $deltas[] = $d; },
            function (string $t) use (&$interims) { $interims[] = $t; },
        );

        $this->assertSame([], $interims);
        $this->assertSame(['hi'], $deltas);
        $this->assertSame('hi', $result['response']);
    }
}
