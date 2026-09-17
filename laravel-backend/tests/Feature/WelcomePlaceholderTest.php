<?php
namespace Tests\Feature;

use App\Models\Tenant\Chatbot;
use App\Support\WidgetDefaults;
use Tests\TestCase;

/**
 * The greeting should be able to name the business and the assistant.
 *
 * A visitor opening the widget was greeted by a bot that said neither where
 * they were nor who was talking, and the merchant's only option was to retype
 * the company name into every chatbot by hand. Both names are already stored.
 */
class WelcomePlaceholderTest extends TestCase
{
    private function bot(array $attrs = []): Chatbot
    {
        $bot = new Chatbot(array_merge([
            'name' => 'Sales Bot', 'language' => 'fa',
            'welcome_message' => 'سلام! به :business خوش آمدید. من :name هستم، چطور می‌توانم کمکتان کنم؟',
            'widget_config' => ['ai_name' => 'هامان بات'],
        ], $attrs));
        $bot->business_name = $attrs['business_name'] ?? 'هامان تک';
        return $bot;
    }

    public function test_it_names_the_business_and_the_assistant(): void
    {
        $merged = WidgetDefaults::merge($this->bot());

        $this->assertSame(
            'سلام! به هامان تک خوش آمدید. من هامان بات هستم، چطور می‌توانم کمکتان کنم؟',
            $merged['welcome_message'],
        );
    }

    public function test_a_missing_business_name_does_not_leak_the_placeholder(): void
    {
        $merged = WidgetDefaults::merge($this->bot(['business_name' => null]));

        $this->assertStringNotContainsString(':business', $merged['welcome_message']);
        $this->assertStringNotContainsString('  ', $merged['welcome_message']);
    }

    public function test_a_greeting_without_placeholders_is_untouched(): void
    {
        $plain = 'سلام! چطور می‌توانم کمکتان کنم؟';

        $merged = WidgetDefaults::merge($this->bot(['welcome_message' => $plain]));

        $this->assertSame($plain, $merged['welcome_message']);
    }

    public function test_the_default_greeting_still_works(): void
    {
        $bot = new Chatbot(['name' => 'B', 'language' => 'fa', 'widget_config' => []]);
        $bot->business_name = null;

        $merged = WidgetDefaults::merge($bot);

        $this->assertNotEmpty($merged['welcome_message']);
        $this->assertStringNotContainsString(':', $merged['welcome_message']);
    }
}
