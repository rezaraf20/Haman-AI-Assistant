<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Guards the bilingual (fa/en) i18n rollout two ways:
 *  1. lang/fa/*.php and lang/en/*.php must define the exact same key set —
 *     a key added to one and forgotten in the other is exactly how a raw
 *     "domain.key" string ends up rendered to a real user.
 *  2. Actually hitting admin pages under both locales and grepping the
 *     rendered HTML for that literal failure mode, in case a hardcoded
 *     string was missed during extraction rather than just under-translated.
 */
class TranslationCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAINS = ['common', 'panel', 'chatbot', 'wallet', 'ticket', 'plan', 'validation'];

    public function test_fa_and_en_locale_files_have_matching_keys(): void
    {
        foreach (self::DOMAINS as $domain) {
            $fa = require base_path("lang/fa/{$domain}.php");
            $en = require base_path("lang/en/{$domain}.php");

            $faKeys = $this->sortedKeys($fa);
            $enKeys = $this->sortedKeys($en);

            $missingFromEn = array_diff($faKeys, $enKeys);
            $missingFromFa = array_diff($enKeys, $faKeys);

            $this->assertEmpty($missingFromEn, "lang/en/{$domain}.php is missing keys present in fa: " . implode(', ', $missingFromEn));
            $this->assertEmpty($missingFromFa, "lang/fa/{$domain}.php is missing keys present in en: " . implode(', ', $missingFromFa));
        }
    }

    public function test_admin_panel_renders_without_raw_translation_keys(): void
    {
        $admin = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('irrelevant'),
            'password_hash' => bcrypt('irrelevant'),
            'name' => 'Test Admin',
            'role' => 'owner',
            'email_verified_at' => now(),
        ]);

        $paths = ['/admin/tenants', '/admin/chatbots', '/admin/api-keys', '/admin/tickets', '/admin/wallet-transactions'];

        foreach (['fa', 'en'] as $locale) {
            $admin->update(['locale' => $locale]);

            foreach ($paths as $path) {
                $response = $this->actingAs($admin, 'web')->get($path);
                $response->assertOk();
                $this->assertNoRawKeyLeaked($response->getContent(), $locale, $path);
            }
        }
    }

    private function sortedKeys(array $arr): array {
        $keys = array_keys($arr);
        sort($keys);
        return $keys;
    }

    private function assertNoRawKeyLeaked(string $html, string $locale, string $path): void {
        foreach (self::DOMAINS as $domain) {
            preg_match_all('/\b' . $domain . '\.[a-z_0-9]+\b/', $html, $matches);
            $this->assertEmpty($matches[0], "Raw translation key(s) leaked into {$path} ({$locale}): " . implode(', ', $matches[0]));
        }
    }
}
