<?php
namespace Tests\Feature;

use App\Support\SignupRisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SignupRisk has two tiers (2026-10-08): compute()'s flags are tag-only
 * signals, never a block on their own (see the class's own docblock and
 * the 2026-10-04 Tor trial-abuse investigation that motivated that
 * policy) — a normal signup must score zero. blockReason() is the
 * separate, stricter tier: a filled honeypot or an impossibly fast
 * submission, certain enough to refuse outright.
 */
class SignupRiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normal_browser_signup_scores_zero(): void
    {
        $result = SignupRisk::compute(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/142.0.0.0 Safari/537.36',
            null,
            45.0,
        );

        $this->assertSame(0, $result['score']);
        $this->assertFalse($result['flags']['invalid_user_agent']);
        $this->assertFalse($result['flags']['honeypot_filled']);
        $this->assertFalse($result['flags']['submitted_too_fast']);
    }

    public function test_empty_user_agent_is_flagged(): void
    {
        $result = SignupRisk::compute('', null, 45.0);
        $this->assertTrue($result['flags']['invalid_user_agent']);
    }

    public function test_escaped_quote_artifact_is_flagged(): void
    {
        // The exact shape found on two of the three Tor trial-abuse
        // signups: a literal \x22 where a real browser would send a plain
        // double-quote inside its own UA string.
        $ua = '\x22Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/142.0.0.0 Safari/537.36\x22';
        $result = SignupRisk::compute($ua, null, 45.0);
        $this->assertTrue($result['flags']['invalid_user_agent']);
    }

    public function test_honeypot_filled_is_flagged(): void
    {
        $result = SignupRisk::compute('Mozilla/5.0', 'http://spam.example', 45.0);
        $this->assertTrue($result['flags']['honeypot_filled']);
    }

    public function test_submitting_in_under_three_seconds_is_flagged(): void
    {
        $result = SignupRisk::compute('Mozilla/5.0', null, 1.2);
        $this->assertTrue($result['flags']['submitted_too_fast']);
    }

    public function test_a_real_human_taking_their_time_is_not_flagged_as_too_fast(): void
    {
        $result = SignupRisk::compute('Mozilla/5.0', null, 30.0);
        $this->assertFalse($result['flags']['submitted_too_fast']);
    }

    public function test_missing_timing_data_is_never_treated_as_suspicious(): void
    {
        // request didn't include form_rendered_at at all (an older cached
        // page, a non-JS client) -- absence of the signal is not itself a
        // signal.
        $result = SignupRisk::compute('Mozilla/5.0', null, null);
        $this->assertFalse($result['flags']['submitted_too_fast']);
    }

    public function test_score_counts_every_flag_tripped(): void
    {
        $result = SignupRisk::compute('', 'filled', 0.5);
        $this->assertSame(3, $result['score']);
    }

    // ── blockReason(): the stricter, "refuse outright" tier ─────────────

    public function test_a_filled_honeypot_is_blocked_regardless_of_timing(): void
    {
        $this->assertSame('honeypot_filled', SignupRisk::blockReason('http://spam.example', 30.0));
        $this->assertSame('honeypot_filled', SignupRisk::blockReason('anything', null));
    }

    public function test_submitting_under_the_block_threshold_is_blocked(): void
    {
        // Default limits.signup_too_fast_block_seconds is 1.0.
        $this->assertSame('submitted_too_fast', SignupRisk::blockReason(null, 0.3));
    }

    public function test_submitting_between_the_block_and_tag_thresholds_is_not_blocked(): void
    {
        // 1.2s: past the 1.0s block threshold, still under the 3.0s tag
        // threshold — compute() tags this, blockReason() must not touch it.
        $this->assertNull(SignupRisk::blockReason(null, 1.2));
    }

    public function test_a_normal_human_timing_is_never_blocked(): void
    {
        $this->assertNull(SignupRisk::blockReason(null, 30.0));
    }

    public function test_missing_timing_data_is_never_blocked(): void
    {
        $this->assertNull(SignupRisk::blockReason(null, null));
    }

    public function test_the_block_threshold_is_admin_configurable(): void
    {
        \App\Support\Settings::set('limits.signup_too_fast_block_seconds', 2.5);

        // 2.0s would pass under the 1.0s default but must now block.
        $this->assertSame('submitted_too_fast', SignupRisk::blockReason(null, 2.0));
    }

    // ── recordBlock(): the only trace a blocked attempt leaves ──────────

    public function test_recording_a_block_writes_one_row(): void
    {
        SignupRisk::recordBlock('honeypot_filled', 'landing', '203.0.113.9', 'curl/8.0');

        $this->assertDatabaseHas('signup_blocks', [
            'reason' => 'honeypot_filled', 'source' => 'landing',
            'ip' => '203.0.113.9', 'user_agent' => 'curl/8.0',
        ]);
    }
}
