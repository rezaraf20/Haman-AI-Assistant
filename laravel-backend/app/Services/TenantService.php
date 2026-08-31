<?php
namespace App\Services;

use App\Models\{Tenant, Plan, User, ApiKey};
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;

/**
 * TenantService
 * Company: شرکت هامان فناوران پیشرو
 * Author: Reza Rafiei
 */
class TenantService
{
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $plan   = Plan::where('slug', 'free')->firstOrFail();
            $uuid   = Str::uuid()->toString();
            $schema = 'tenant_' . str_replace('-', '', $uuid);

            $tenant = Tenant::create([
                'id'            => $uuid,
                'slug'          => Str::slug($data['name']) . '-' . substr($uuid, 0, 6),
                'name'          => $data['name'],
                'email'         => $data['email'],
                'plan_id'       => $plan->id,
                'schema_name'   => $schema,
                'status'        => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'settings'      => ['webhook_secret' => Str::random(32)],
            ]);

            $this->createSchema($schema);

            $user = User::create([
                'id'              => Str::uuid()->toString(),
                'tenant_id'       => $tenant->id,
                'email'           => $data['email'],
                'password'        => Hash::make($data['password']),
                'password_hash'   => Hash::make($data['password']),
                'name'            => $data['name'],
                'role'            => 'owner',
                'email_verified_at' => now(),
            ]);

            $rawKey = 'hfp_' . Str::random(32);
            $prefix = substr($rawKey, 0, 12);
            ApiKey::create([
                'id'         => Str::uuid()->toString(),
                'tenant_id'  => $tenant->id,
                'created_by' => $user->id,
                'name'       => 'WordPress Plugin',
                'key_prefix' => $prefix,
                'key_hash'   => password_hash($rawKey, PASSWORD_BCRYPT, ['cost' => 12]),
                'scopes'     => ['read', 'write', 'sync', 'chat'],
            ]);

            return ['tenant' => $tenant, 'user' => $user, 'api_key' => $rawKey];
        });
    }

    /**
     * Customer-portal self-signup via phone+SMS-OTP (see SmsService /
     * app/Livewire/OtpLogin.php) — no email/password at all. Deliberately
     * doesn't auto-create a "WordPress Plugin" API key like register() does:
     * keys are now always bound to a specific chatbot, created through the
     * portal's "Buy New Chatbot" flow, not handed out unbound at signup.
     */
    public function registerViaPhone(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $plan   = Plan::where('slug', 'free')->firstOrFail();
            $uuid   = Str::uuid()->toString();
            $schema = 'tenant_' . str_replace('-', '', $uuid);
            $name   = trim($data['first_name'] . ' ' . $data['last_name']);

            $tenant = Tenant::create([
                'id'            => $uuid,
                'slug'          => Str::slug($name ?: $data['phone']) . '-' . substr($uuid, 0, 6),
                'name'          => $name,
                'email'         => $data['email'],
                'phone'         => $data['phone'],
                'plan_id'       => $plan->id,
                'schema_name'   => $schema,
                'status'        => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'settings'      => ['webhook_secret' => Str::random(32)],
            ]);

            $this->createSchema($schema);

            $user = User::create([
                'id'                => Str::uuid()->toString(),
                'tenant_id'         => $tenant->id,
                'email'             => $data['email'],
                'phone'             => $data['phone'],
                'first_name'        => $data['first_name'],
                'last_name'         => $data['last_name'],
                'national_id'       => $data['national_id'],
                'address'           => $data['address'],
                // Never used to log in (auth is phone+OTP only) — set to an
                // unguessable random value rather than leaving it empty.
                'password'          => Hash::make(Str::random(40)),
                'password_hash'     => Hash::make(Str::random(40)),
                'name'              => $name,
                'role'              => 'owner',
                'email_verified_at' => now(),
            ]);

            return ['tenant' => $tenant, 'user' => $user];
        });
    }

    public function createSchema(string $schemaName): void
    {
        DB::statement("CREATE SCHEMA IF NOT EXISTS {$schemaName}");
        $this->createTenantTables($schemaName);
    }

    public function fixSchema(string $schemaName): void
    {
        // Fix missing columns in existing schemas
        $tables = [
            'chatbots'      => ['created_at', 'updated_at'],
            'documents'     => ['created_at', 'updated_at'],
            'conversations' => ['created_at', 'updated_at'],
            'messages'      => ['created_at'],
            'sync_jobs'     => ['created_at'],
            'faqs'          => ['created_at', 'updated_at'],
            'products'      => ['created_at', 'updated_at'],
        ];
        foreach ($tables as $table => $cols) {
            foreach ($cols as $col) {
                try {
                    DB::statement("ALTER TABLE {$schemaName}.{$table} ADD COLUMN IF NOT EXISTS {$col} TIMESTAMPTZ DEFAULT now()");
                } catch (\Throwable $e) {}
            }
        }
    }

    private function createTenantTables(string $s): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.chatbots (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                name VARCHAR(255) NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'support',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                system_prompt TEXT,
                welcome_message TEXT,
                fallback_response TEXT,
                embedding_model VARCHAR(100) NOT NULL DEFAULT 'models/text-embedding-004',
                llm_model VARCHAR(100) NOT NULL DEFAULT 'gemini-1.5-flash',
                temperature DECIMAL(3,2) NOT NULL DEFAULT 0.30,
                max_tokens_response SMALLINT NOT NULL DEFAULT 800,
                retrieval_top_k SMALLINT NOT NULL DEFAULT 8,
                retrieval_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.600,
                reranker_enabled BOOLEAN NOT NULL DEFAULT false,
                memory_window SMALLINT NOT NULL DEFAULT 6,
                widget_config JSONB NOT NULL DEFAULT '{}',
                language VARCHAR(10) NOT NULL DEFAULT 'en',
                response_language VARCHAR(10) NOT NULL DEFAULT 'auto',
                is_active BOOLEAN NOT NULL DEFAULT true,
                total_conversations BIGINT NOT NULL DEFAULT 0,
                total_messages BIGINT NOT NULL DEFAULT 0,
                total_tokens_used BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.chatbot_domains (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                domain VARCHAR(255) NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT true,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE(chatbot_id, domain)
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.documents (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                source_type VARCHAR(30) NOT NULL,
                source_url TEXT,
                external_id VARCHAR(255),
                title VARCHAR(500) NOT NULL,
                raw_content TEXT NOT NULL,
                content_hash CHAR(64) NOT NULL,
                language VARCHAR(10) NOT NULL DEFAULT 'en',
                metadata JSONB NOT NULL DEFAULT '{}',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                chunk_count SMALLINT NOT NULL DEFAULT 0,
                error_message TEXT,
                retry_count SMALLINT NOT NULL DEFAULT 0,
                last_synced_at TIMESTAMPTZ,
                indexed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE(chatbot_id, source_type, external_id)
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.chunks (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                document_id UUID NOT NULL REFERENCES {$s}.documents(id) ON DELETE CASCADE,
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                chunk_index SMALLINT NOT NULL,
                content TEXT NOT NULL,
                embedding vector(3072),
                metadata JSONB NOT NULL DEFAULT '{}',
                token_count SMALLINT NOT NULL DEFAULT 0,
                embedding_model VARCHAR(100) NOT NULL DEFAULT 'text-embedding-004',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_chunks_chatbot ON {$s}.chunks(chatbot_id)");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.products (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                woo_product_id BIGINT NOT NULL,
                name VARCHAR(500) NOT NULL,
                slug VARCHAR(500),
                sku VARCHAR(255),
                type VARCHAR(30) DEFAULT 'simple',
                status VARCHAR(20) DEFAULT 'publish',
                description TEXT,
                short_description TEXT,
                price DECIMAL(12,4),
                regular_price DECIMAL(12,4),
                sale_price DECIMAL(12,4),
                currency CHAR(3) DEFAULT 'USD',
                stock_status VARCHAR(20) DEFAULT 'instock',
                stock_quantity INTEGER,
                average_rating DECIMAL(3,2),
                review_count INTEGER DEFAULT 0,
                permalink TEXT,
                featured_image TEXT,
                attributes JSONB DEFAULT '{}',
                tags TEXT[] DEFAULT '{}',
                embedding_status VARCHAR(20) DEFAULT 'pending',
                synced_at TIMESTAMPTZ DEFAULT now(),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE(chatbot_id, woo_product_id)
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.faqs (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                question TEXT NOT NULL,
                answer TEXT NOT NULL,
                category VARCHAR(255),
                source VARCHAR(30) DEFAULT 'manual',
                language VARCHAR(10) DEFAULT 'en',
                is_active BOOLEAN DEFAULT true,
                sort_order SMALLINT DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.conversations (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                session_id VARCHAR(128) NOT NULL,
                visitor_id VARCHAR(128),
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                language VARCHAR(10) DEFAULT 'en',
                page_url TEXT,
                referrer TEXT,
                ip_country CHAR(2),
                device_type VARCHAR(20),
                browser VARCHAR(50),
                message_count SMALLINT DEFAULT 0,
                total_tokens INTEGER DEFAULT 0,
                is_converted BOOLEAN DEFAULT false,
                ended_at TIMESTAMPTZ,
                started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE(chatbot_id, session_id)
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_conv_bot ON {$s}.conversations(chatbot_id, created_at DESC)");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.messages (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                conversation_id UUID NOT NULL REFERENCES {$s}.conversations(id) ON DELETE CASCADE,
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                role VARCHAR(20) NOT NULL,
                content TEXT NOT NULL,
                retrieved_chunk_ids JSONB NOT NULL DEFAULT '[]',
                retrieval_scores    JSONB NOT NULL DEFAULT '[]',
                prompt_tokens INTEGER DEFAULT 0,
                completion_tokens INTEGER DEFAULT 0,
                total_tokens INTEGER DEFAULT 0,
                model_used VARCHAR(100),
                latency_ms INTEGER,
                is_fallback BOOLEAN DEFAULT false,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_msg_conv ON {$s}.messages(conversation_id, created_at ASC)");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.sync_jobs (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                job_type VARCHAR(30) NOT NULL,
                triggered_by VARCHAR(30) DEFAULT 'plugin',
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                items_total INTEGER DEFAULT 0,
                items_processed INTEGER DEFAULT 0,
                items_failed INTEGER DEFAULT 0,
                error_log JSONB DEFAULT '[]',
                payload JSONB DEFAULT '{}',
                result JSONB,
                retry_count SMALLINT DEFAULT 0,
                started_at TIMESTAMPTZ,
                completed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");

        DB::statement("SET search_path TO public");
    }

    public function incrementUsage(Tenant $tenant, int $tokens, int $msgs = 1): void
    {
        // Plan quota already used up by a prior message this month? This
        // message is running on purchased bonus_tokens (BuyTokens page) —
        // draw it down accordingly. Uses the pre-increment usage snapshot on
        // $tenant, which ChatService loads fresh right before this call.
        $planLimit = $tenant->plan->max_tokens_monthly ?? PHP_INT_MAX;
        if ($tenant->usage_tokens_current >= $planLimit && $tenant->bonus_tokens > 0) {
            Tenant::where('id', $tenant->id)->decrement('bonus_tokens', min($tokens, $tenant->bonus_tokens));
        }

        Tenant::where('id', $tenant->id)->increment('usage_tokens_current', $tokens);
        Tenant::where('id', $tenant->id)->increment('usage_messages_current', $msgs);
        Tenant::where('id', $tenant->id)->update(['last_active_at' => now()]);
    }

    public function resetMonthlyUsage(): void
    {
        Tenant::query()->update(['usage_tokens_current' => 0, 'usage_messages_current' => 0]);
    }
}
