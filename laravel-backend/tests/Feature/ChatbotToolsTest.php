<?php
namespace Tests\Feature;

use App\Support\ChatbotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The switch that decides whether a tool exists for a given chatbot.
 *
 * The Python registry has always gated every tool on enabled_tools, and that
 * gate worked perfectly. What was missing was any way to put a name into the
 * list, so every chatbot in production sat at [] and no tool had ever run —
 * which is why compare_products never produced a compared_pair event. The
 * logging was fine; the tool was unreachable.
 */
class ChatbotToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_matches_the_python_registry(): void
    {
        // If these drift, a tool is either unswitchable from the panel or
        // offered in the panel and ignored by the service.
        $registryDir = base_path('../python-ai-service/app/services/tools');

        if (!is_dir($registryDir)) {
            $this->markTestSkipped('Python tree not present in this image.');
        }

        $registered = [];
        foreach (glob($registryDir . '/*.py') as $file) {
            preg_match_all('/name="([a-z_]+)"/', file_get_contents($file), $m);
            $registered = array_merge($registered, $m[1]);
        }
        $registered = array_values(array_unique($registered));
        sort($registered);

        $catalogue = ChatbotTools::names();
        sort($catalogue);

        $this->assertSame($registered, $catalogue,
            'ChatbotTools::CATALOGUE and the Python tool registry disagree.');
    }

    public function test_every_tool_declares_what_it_can_spend(): void
    {
        foreach (ChatbotTools::CATALOGUE as $name => $definition) {
            $this->assertContains($definition['cost'], ['none', 'live_query', 'sms', 'money'],
                "{$name} has an undeclared cost class.");
            $this->assertNotEmpty($definition['group'], "{$name} has no group.");
        }
    }

    public function test_every_tool_is_labelled_in_both_languages(): void
    {
        $fa = require lang_path('fa/chatbot.php');
        $en = require lang_path('en/chatbot.php');

        foreach (ChatbotTools::names() as $name) {
            foreach (["tool_{$name}", "tool_{$name}_help"] as $key) {
                $this->assertArrayHasKey($key, $fa, "{$name} has no Persian label.");
                $this->assertArrayHasKey($key, $en, "{$name} has no English label.");
            }
        }

        foreach (['live_query', 'sms', 'money'] as $cost) {
            $this->assertArrayHasKey("tool_cost_{$cost}", $fa);
            $this->assertArrayHasKey("tool_cost_{$cost}", $en);
        }
    }

    public function test_an_unknown_tool_name_is_dropped_on_save(): void
    {
        $clean = ChatbotTools::sanitise([
            'compare_products', 'not_a_real_tool', 'recommend_products', '',
        ]);

        $this->assertEqualsCanonicalizing(['compare_products', 'recommend_products'], $clean,
            'An unknown name would sit in the column where the panel could never switch it off.');
    }

    public function test_sanitise_preserves_order_from_the_catalogue_not_the_input(): void
    {
        // Stable output means saving the same set twice does not produce a
        // different column value and a spurious "changed" in the audit log.
        $a = ChatbotTools::sanitise(['compare_products', 'search_products']);
        $b = ChatbotTools::sanitise(['search_products', 'compare_products']);

        $this->assertSame($a, $b);
    }

    public function test_an_empty_selection_disables_every_tool(): void
    {
        $this->assertSame([], ChatbotTools::sanitise([]),
            'Switching everything off must be expressible.');
    }

    public function test_the_costly_tools_are_marked_as_such(): void
    {
        // These three spend the merchant's money or reach their live site.
        // The merchant sees that next to the switch, not on an invoice.
        $this->assertSame('sms', ChatbotTools::cost('get_order_status'));
        $this->assertSame('money', ChatbotTools::cost('create_payment_link'));
        $this->assertSame('live_query', ChatbotTools::cost('add_to_cart'));

        $this->assertSame('none', ChatbotTools::cost('compare_products'));
    }
}
