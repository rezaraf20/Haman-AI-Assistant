<?php
namespace Tests\Feature;

use App\Support\SignupRisk;
use Tests\TestCase;

/**
 * SignupRisk::compute() — every flag here is explicitly a signal, never a
 * block (see the class's own docblock and the 2026-10-04 Tor trial-abuse
 * investigation that motivated it): a normal signup must score zero, and
 * nothing in this class ever refuses anything on its own.
 */
class SignupRiskTest extends TestCase
{
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
}
