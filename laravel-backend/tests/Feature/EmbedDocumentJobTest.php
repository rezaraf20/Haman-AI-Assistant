<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Jobs\EmbedDocumentJob;
use App\Models\{Plan, Tenant};
use App\Models\Tenant\Document;
use App\Services\{AiGatewayService, TenantService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The job used to leave search_path pointed at the tenant's schema after
 * handle() returned — on success AND on a caught-then-rethrown embedding
 * failure. Queue workers reuse one DB connection across jobs, so whatever
 * ran next on that worker would silently run against the wrong schema.
 * Same class of bug every other cross-schema loop in this app already
 * guards against with a finally block.
 */
class EmbedDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithDocument(): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-' . Str::random(8),
            'price_monthly' => 0, 'max_chatbots' => 1, 'max_tokens_monthly' => 1000000,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $tenant = Tenant::create([
            'slug' => 'test-' . Str::random(8), 'name' => 'Test Tenant',
            'email' => Str::random(12) . '@example.test', 'plan_id' => $plan->id,
            'schema_name' => 'placeholder', 'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $schema = 'tenant_' . str_replace('-', '', $tenant->id);
        $tenant->update(['schema_name' => $schema]);
        app(TenantService::class)->createSchema($schema);

        $chatbotId = (string) Str::uuid();
        DB::statement("SET search_path TO {$schema}, public");
        DB::table('chatbots')->insert([
            'id' => $chatbotId, 'name' => 'Test Bot', 'type' => 'support', 'status' => 'active',
            'is_active' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $doc = Document::create([
            'chatbot_id' => $chatbotId, 'source_type' => 'manual', 'title' => 'Doc',
            'raw_content' => 'Some content.', 'language' => 'en', 'status' => 'pending',
        ]);
        DB::statement('SET search_path TO public');

        return ['schema' => $schema, 'chatbotId' => $chatbotId, 'documentId' => $doc->id];
    }

    private function currentSchema(): string
    {
        return DB::selectOne('select current_schema() as schema')->schema;
    }

    public function test_a_successful_embed_resets_search_path_to_public(): void
    {
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'documentId' => $documentId] = $this->makeTenantWithDocument();

        $this->mock(AiGatewayService::class, function ($mock) {
            $mock->shouldReceive('embedDocument')->once()->andReturn(null);
        });

        (new EmbedDocumentJob($documentId, $chatbotId, $schema))->handle(app(AiGatewayService::class));

        $this->assertSame('public', $this->currentSchema());

        DB::statement("SET search_path TO {$schema}, public");
        $status = DB::table('documents')->where('id', $documentId)->value('status');
        DB::statement('SET search_path TO public');
        $this->assertEquals('indexed', $status);
    }

    public function test_a_failed_embed_still_resets_search_path_to_public(): void
    {
        ['schema' => $schema, 'chatbotId' => $chatbotId, 'documentId' => $documentId] = $this->makeTenantWithDocument();

        $this->mock(AiGatewayService::class, function ($mock) {
            $mock->shouldReceive('embedDocument')->once()->andThrow(new \RuntimeException('AI service unreachable'));
        });

        try {
            (new EmbedDocumentJob($documentId, $chatbotId, $schema))->handle(app(AiGatewayService::class));
            $this->fail('handle() must rethrow so the queue worker retries the job.');
        } catch (\RuntimeException $e) {
            $this->assertSame('AI service unreachable', $e->getMessage());
        }

        $this->assertSame('public', $this->currentSchema(), 'search_path must be reset to public even when the job fails and rethrows');

        DB::statement("SET search_path TO {$schema}, public");
        $doc = DB::table('documents')->where('id', $documentId)->first();
        DB::statement('SET search_path TO public');
        $this->assertEquals('pending', $doc->status);
        $this->assertEquals(1, $doc->retry_count);
    }
}
