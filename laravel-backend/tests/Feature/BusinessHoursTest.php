<?php
namespace Tests\Feature;

use App\Support\BusinessHours;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * BusinessHours::isOpenNow()/describe() — the PHP-side "is it open right
 * now" calculation that lets the bot say "yes, open until 18:00" instead of
 * just reading back the schedule and leaving the arithmetic to the LLM
 * (unreliable at date/time math). All assertions pin the clock with
 * Carbon::setTestNow() rather than depending on when the suite happens to
 * run.
 */
class BusinessHoursTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(): array
    {
        return [
            ['days' => ['sat', 'sun', 'mon', 'tue', 'wed'], 'closed' => false, 'from' => '09:00', 'to' => '18:00'],
            ['days' => ['thu'], 'closed' => false, 'from' => '09:00', 'to' => '13:00'],
            ['days' => ['fri'], 'closed' => true],
        ];
    }

    public function test_open_during_a_weekday_window(): void
    {
        // 2026-10-10 is a Saturday.
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Asia/Tehran'));

        $status = BusinessHours::isOpenNow($this->schedule(), 'Asia/Tehran');

        $this->assertTrue($status['is_open']);
        $this->assertSame('sat', $status['today_key']);
        $this->assertSame('18:00', $status['to']);
    }

    public function test_closed_before_opening_time_on_a_working_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 07:00:00', 'Asia/Tehran'));

        $status = BusinessHours::isOpenNow($this->schedule(), 'Asia/Tehran');

        $this->assertFalse($status['is_open']);
    }

    public function test_closed_exactly_at_closing_time(): void
    {
        // Half-open interval: [from, to) — the closing minute itself is closed.
        Carbon::setTestNow(Carbon::parse('2026-10-10 18:00:00', 'Asia/Tehran'));

        $status = BusinessHours::isOpenNow($this->schedule(), 'Asia/Tehran');

        $this->assertFalse($status['is_open']);
    }

    public function test_closed_on_a_day_marked_closed(): void
    {
        // 2026-10-16 is a Friday.
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Tehran'));

        $status = BusinessHours::isOpenNow($this->schedule(), 'Asia/Tehran');

        $this->assertFalse($status['is_open']);
        $this->assertSame('fri', $status['today_key']);
    }

    public function test_closed_on_a_day_no_row_covers(): void
    {
        $partial = [['days' => ['sat'], 'closed' => false, 'from' => '09:00', 'to' => '18:00']];
        // 2026-10-16 is a Friday, not covered by the schedule above.
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Tehran'));

        $status = BusinessHours::isOpenNow($partial, 'Asia/Tehran');

        $this->assertFalse($status['is_open']);
    }

    public function test_empty_schedule_returns_null_not_a_false_closed(): void
    {
        $this->assertNull(BusinessHours::isOpenNow([], 'Asia/Tehran'));
    }

    public function test_describe_compresses_a_contiguous_run_of_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Asia/Tehran'));

        $text = BusinessHours::describe($this->schedule(), 'Asia/Tehran', null, null);

        $this->assertStringContainsString('Sat-Wed: 09:00', $text);
        $this->assertStringContainsString('Thu: 09:00', $text);
        $this->assertStringContainsString('Fri: closed', $text);
        $this->assertStringContainsString('OPEN, closes at 18:00', $text);
    }

    public function test_describe_includes_exceptions_when_given(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Asia/Tehran'));

        $text = BusinessHours::describe($this->schedule(), 'Asia/Tehran', 'تعطیلات رسمی بسته است', 'Closed on public holidays');

        $this->assertStringContainsString('Exceptions:', $text);
        $this->assertStringContainsString('fa: تعطیلات رسمی بسته است', $text);
        $this->assertStringContainsString('en: Closed on public holidays', $text);
    }

    public function test_describe_omits_exceptions_block_when_none_given(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Asia/Tehran'));

        $text = BusinessHours::describe($this->schedule(), 'Asia/Tehran', null, null);

        $this->assertStringNotContainsString('Exceptions:', $text);
    }
}
