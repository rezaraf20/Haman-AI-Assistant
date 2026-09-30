<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Enums\ChatbotType;

/**
 * Every admin selector/badge that shows a chatbot TYPE itself (not a
 * ChatbotTypePrice catalogue row's own bilingual name) used to print the
 * bare English enum value via ucfirst($case->value), unlabelled and
 * unlocalized regardless of the panel's language.
 */
class ChatbotTypeLabelTest extends TestCase
{
    public function test_every_case_has_a_persian_and_an_english_label(): void
    {
        $fa = require lang_path('fa/chatbot.php');
        $en = require lang_path('en/chatbot.php');

        foreach (ChatbotType::cases() as $case) {
            $key = 'type_' . $case->value;
            $this->assertArrayHasKey($key, $fa, "{$case->value} has no Persian label.");
            $this->assertArrayHasKey($key, $en, "{$case->value} has no English label.");
        }
    }

    public function test_label_follows_the_active_locale(): void
    {
        app()->setLocale('en');
        $this->assertEquals('WooCommerce', ChatbotType::WooCommerce->label());

        app()->setLocale('fa');
        $this->assertEquals('ووکامرس', ChatbotType::WooCommerce->label());
    }
}
