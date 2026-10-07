<?php
namespace Tests\Feature;

use App\Models\Tenant\Chatbot;
use Tests\TestCase;

/**
 * Chatbot::businessProfilePromptBlock() / businessProfileMissingCore() — the
 * two methods the whole business-profile feature rests on (see
 * ChatService::gatewayPayload() for the former, the onboarding banner /
 * WidgetSettings for the latter). No DB needed: both are pure functions of
 * the model's own in-memory attributes.
 */
class BusinessProfileTest extends TestCase
{
    public function test_empty_profile_renders_no_block(): void
    {
        $bot = new Chatbot();
        $bot->business_profile = [];

        $this->assertNull($bot->businessProfilePromptBlock());
    }

    public function test_block_states_only_whats_actually_filled_in(): void
    {
        $bot = new Chatbot();
        $bot->business_name = 'هامان تک';
        $bot->business_profile = [
            'address' => ['fa' => 'تهران، خیابان آزادی'],
            'phones' => ['02112345678'],
        ];

        $block = $bot->businessProfilePromptBlock();

        $this->assertStringContainsString('هامان تک', $block);
        $this->assertStringContainsString('تهران، خیابان آزادی', $block);
        $this->assertStringContainsString('02112345678', $block);
        // Nothing invented for fields that were never filled in.
        $this->assertStringNotContainsString('City', $block);
        $this->assertStringNotContainsString('Founded', $block);
        $this->assertStringContainsString('never invent, guess, or infer', $block);
    }

    public function test_bilingual_field_includes_both_languages_labelled(): void
    {
        $bot = new Chatbot();
        $bot->business_profile = [
            'description' => ['fa' => 'یک فروشگاه آنلاین', 'en' => 'An online shop'],
        ];

        $block = $bot->businessProfilePromptBlock();

        $this->assertStringContainsString('About (fa): یک فروشگاه آنلاین', $block);
        $this->assertStringContainsString('About (en): An online shop', $block);
    }

    public function test_dead_end_instruction_offers_known_phone_when_present(): void
    {
        $bot = new Chatbot();
        $bot->business_profile = ['phones' => ['09120000000'], 'emails' => ['x@example.com']];

        $block = $bot->businessProfilePromptBlock();

        // Phone wins over email when both are known (see the model's own
        // match() ordering) — this is the mechanism that stops a profile
        // gap from being a bare "we don't have that, contact us".
        $this->assertStringContainsString('Offer the phone number 09120000000 instead', $block);
        $this->assertStringNotContainsString('Say plainly that it is not listed', $block);
    }

    public function test_dead_end_instruction_falls_back_to_plain_statement_when_no_contact_known(): void
    {
        $bot = new Chatbot();
        $bot->business_profile = ['description' => ['fa' => 'یک فروشگاه']];

        $block = $bot->businessProfilePromptBlock();

        $this->assertStringContainsString('Say plainly that it is not listed', $block);
    }

    public function test_missing_core_reports_all_four_when_profile_is_empty(): void
    {
        $bot = new Chatbot();
        $bot->business_profile = [];

        $this->assertSame(
            ['address', 'phones_or_emails', 'working_hours', 'description'],
            $bot->businessProfileMissingCore()
        );
    }

    public function test_missing_core_is_empty_once_all_four_are_filled(): void
    {
        $bot = new Chatbot();
        $bot->business_profile = [
            'address' => ['fa' => 'تهران'],
            'phones' => ['09120000000'],
            'working_hours_schedule' => [['days' => ['sat'], 'closed' => false, 'from' => '09:00', 'to' => '18:00']],
            'description' => ['fa' => 'یک فروشگاه'],
        ];

        $this->assertSame([], $bot->businessProfileMissingCore());
    }

    public function test_missing_core_accepts_either_phone_or_email_not_both(): void
    {
        $bot = new Chatbot();
        $bot->business_profile = [
            'address' => ['fa' => 'تهران'],
            'emails' => ['x@example.com'],
            'working_hours_schedule' => [['days' => ['sat'], 'closed' => false, 'from' => '09:00', 'to' => '18:00']],
            'description' => ['fa' => 'یک فروشگاه'],
        ];

        $this->assertSame([], $bot->businessProfileMissingCore());
    }

    public function test_missing_core_counts_a_schedule_of_only_closed_rows_as_filled_in(): void
    {
        // A merchant who is genuinely closed every day of the week still
        // filled this field in deliberately — that's different from never
        // having touched it at all, which is what businessProfileMissingCore()
        // actually guards against.
        $bot = new Chatbot();
        $bot->business_profile = [
            'address' => ['fa' => 'تهران'],
            'phones' => ['09120000000'],
            'working_hours_schedule' => [['days' => \App\Support\BusinessHours::WEEKDAYS, 'closed' => true]],
            'description' => ['fa' => 'یک فروشگاه'],
        ];

        $this->assertSame([], $bot->businessProfileMissingCore());
    }
}
