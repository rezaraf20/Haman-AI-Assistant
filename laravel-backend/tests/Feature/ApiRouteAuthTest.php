<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{ApiKey, Tenant, Plan, User};
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Guards the routing trap that broke every WordPress plugin install.
 *
 * routes/api.php defines a plugin group (API key) and a dashboard group
 * (Sanctum). Both defined chatbot routes. Laravel's route lookup is keyed
 * on method+URI, so the dashboard group — registered second — silently
 * overwrote `GET v1/chatbots`. The plugin's own "test connection" button
 * calls that endpoint and got 401 on every site, with nothing in
 * route:list to show the route had been replaced.
 *
 * Two failure modes, and both are covered here:
 *   1. Identical literal URI: the later definition overwrites the earlier
 *      one, so only one survives and route:list looks clean. Caught by
 *      asserting the endpoint really answers an API key.
 *   2. Same path, different parameter names ({id} vs {chatbot}): both
 *      register, and whichever was declared first wins at match time.
 *      Caught by the pattern scan at the bottom.
 */
class ApiRouteAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithKey(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 5, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Shop',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $user = User::create([
            'tenant_id' => $tenant->id, 'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Owner', 'role' => 'owner', 'email_verified_at' => now(),
        ]);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'fa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET search_path TO public');

        DB::table('chatbot_index')->insert([
            'chatbot_id' => $chatbotId, 'tenant_id' => $tenant->id, 'schema_name' => $schema,
            'is_active' => true, 'name' => 'Test Bot',
        ]);

        $raw = 'hfp_' . Str::random(32);
        ApiKey::create([
            'tenant_id' => $tenant->id, 'created_by' => $user->id,
            'name' => 'WordPress Plugin', 'key_prefix' => substr($raw, 0, 12),
            'key_hash' => password_hash($raw, PASSWORD_BCRYPT, ['cost' => 4]),
            'scopes' => ['read', 'write', 'sync', 'chat'],
        ]);

        return compact('tenant', 'schema', 'user', 'raw', 'chatbotId');
    }

    // ── The two the plugin depends on ───────────────────────────────────

    public function test_the_plugin_can_list_chatbots_with_its_api_key(): void
    {
        ['raw' => $raw] = $this->makeTenantWithKey();

        $response = $this->getJson('/api/v1/chatbots', ['Authorization' => 'Bearer ' . $raw]);

        $response->assertOk();
    }

    public function test_listing_chatbots_without_a_key_is_rejected(): void
    {
        $this->makeTenantWithKey();

        $this->getJson('/api/v1/chatbots')->assertStatus(401);
    }

    public function test_an_invalid_key_is_rejected(): void
    {
        $this->makeTenantWithKey();

        $this->getJson('/api/v1/chatbots', ['Authorization' => 'Bearer hfp_' . Str::random(32)])
            ->assertStatus(401);
    }

    public function test_the_plugin_can_fetch_one_chatbot_with_its_api_key(): void
    {
        ['raw' => $raw, 'chatbotId' => $id] = $this->makeTenantWithKey();

        $this->getJson('/api/v1/chatbots/' . $id, ['Authorization' => 'Bearer ' . $raw])
            ->assertOk();
    }

    public function test_fetching_one_chatbot_without_a_key_is_rejected(): void
    {
        ['chatbotId' => $id] = $this->makeTenantWithKey();

        $this->getJson('/api/v1/chatbots/' . $id)->assertStatus(401);
    }

    public function test_the_plugin_can_read_the_webhook_secret_with_its_api_key(): void
    {
        ['raw' => $raw] = $this->makeTenantWithKey();

        $this->getJson('/api/v1/tenant/webhook-secret', ['Authorization' => 'Bearer ' . $raw])
            ->assertOk();
    }

    // ── The permanent guard ─────────────────────────────────────────────

    /**
     * Two API routes must never match the same concrete path with the same
     * method. Parameter names are normalised away, because {id} and
     * {chatbot} are the same path as far as matching is concerned — that
     * difference is precisely what let one of these hide.
     */
    public function test_no_two_api_routes_match_the_same_path_and_method(): void
    {
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/v1')) continue;

            $normalized = preg_replace('/\{[^}]+\}/', '*', $uri);
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) continue;
                $seen["{$method} {$normalized}"][] = $uri;
            }
        }

        $collisions = array_filter($seen, fn ($uris) => count($uris) > 1);

        $this->assertEmpty(
            $collisions,
            "Two API routes match the same path+method, so one silently shadows the other:\n" .
            implode("\n", array_map(
                fn ($k, $v) => "  {$k}  <-  " . implode(' , ', $v),
                array_keys($collisions),
                $collisions
            ))
        );
    }

    /**
     * The narrower failure the scan above cannot see: when two definitions
     * share the *identical* literal URI, the later one overwrites the
     * earlier in the lookup and only one remains to be scanned. So assert
     * directly that the endpoints the plugin needs are the API-key ones.
     */
    public function test_the_plugin_endpoints_are_bound_to_the_api_key_guard(): void
    {
        foreach ([['GET', 'api/v1/chatbots'], ['GET', 'api/v1/chatbots/{id}'],
                  ['GET', 'api/v1/tenant/webhook-secret']] as [$method, $uri]) {
            $match = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
            );

            $this->assertNotNull($match, "{$method} {$uri} is not registered at all.");
            $this->assertContains(
                'auth.apikey',
                $match->gatherMiddleware(),
                "{$method} {$uri} is no longer behind the API key guard — the plugin will get 401."
            );
        }
    }
}
