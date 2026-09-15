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

    /**
     * Customer-portal self-signup via email+password (see EmailLogin
     * Livewire component) — the English-locale counterpart to
     * registerViaPhone()'s Persian-locale phone+SMS-OTP flow. Deliberately
     * collects only name/email/password up front, same reasoning as
     * registerViaPhone()'s API-key omission: the rest of the profile
     * (first/last name split, address, national ID) is filled in later from
     * the customer's own Profile page, not forced at signup time.
     */
    public function registerViaEmail(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $plan   = Plan::where('slug', 'free')->firstOrFail();
            $uuid   = Str::uuid()->toString();
            $schema = 'tenant_' . str_replace('-', '', $uuid);
            $name   = trim($data['name']);
            // Best-effort split so Profile's first_name/last_name fields
            // start pre-filled instead of blank — the customer can correct
            // this later from their own profile either way.
            $nameParts = preg_split('/\s+/', $name, 2);

            $tenant = Tenant::create([
                'id'            => $uuid,
                'slug'          => Str::slug($name) . '-' . substr($uuid, 0, 6),
                'name'          => $name,
                'email'         => $data['email'],
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
                'first_name'        => $nameParts[0] ?? $name,
                'last_name'         => $nameParts[1] ?? '',
                'password'          => Hash::make($data['password']),
                'password_hash'     => Hash::make($data['password']),
                'name'              => $name,
                'role'              => 'owner',
                'locale'            => 'en',
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
        // Not a timestamp column like everything above, so it can't share that
        // loop — the computed cost of the message's LLM call (see
        // LlmProviderProfile's per-1M-token prices and ChatService::sendMessage()).
        try {
            DB::statement("ALTER TABLE {$schemaName}.messages ADD COLUMN IF NOT EXISTS cost_toman DECIMAL(14,4) NOT NULL DEFAULT 0");
        } catch (\Throwable $e) {}
        // Set by the Python RAG service (rag_service.py) whenever retrieval
        // found no chunk above the chatbot's own retrieval_threshold — a real
        // content-gap signal, not a technical failure, that drives the
        // customer portal's demand-gap dashboard (DemandGap.php).
        try {
            DB::statement("ALTER TABLE {$schemaName}.messages ADD COLUMN IF NOT EXISTS is_unanswered BOOLEAN NOT NULL DEFAULT false");
        } catch (\Throwable $e) {}
        // Hybrid search: full-text column + GIN index alongside the existing
        // vector column, and the per-chatbot threshold rerank scores get
        // checked against (raw cosine similarity and a cross-encoder-style
        // rerank score are on different scales — see rag_service.py).
        try {
            DB::statement("ALTER TABLE {$schemaName}.chunks ADD COLUMN IF NOT EXISTS content_tsv TSVECTOR");
        } catch (\Throwable $e) {}
        try {
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_chunks_tsv ON {$schemaName}.chunks USING GIN(content_tsv)");
        } catch (\Throwable $e) {}
        try {
            DB::statement("ALTER TABLE {$schemaName}.chatbots ADD COLUMN IF NOT EXISTS rerank_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.500");
        } catch (\Throwable $e) {}
        try {
            DB::statement("ALTER TABLE {$schemaName}.chatbots ADD COLUMN IF NOT EXISTS business_name VARCHAR(255)");
        } catch (\Throwable $e) {}
        try {
            // products.tags was TEXT[] (Postgres native array) while
            // Product's 'tags' => 'array' Eloquent cast always JSON-encodes
            // — every single product sync failed with "malformed array
            // literal" as a result (100% failure rate, confirmed zero rows
            // in the products table for any tenant). to_jsonb() on the
            // existing text[] column correctly converts '{a,b}' to
            // ["a","b"]; only runs if the column is still the old type.
            DB::statement("
                DO \$\$
                BEGIN
                    IF (SELECT data_type FROM information_schema.columns WHERE table_schema = '{$schemaName}' AND table_name = 'products' AND column_name = 'tags') = 'ARRAY' THEN
                        -- The existing DEFAULT '{}' (a Postgres array
                        -- literal) can't be auto-cast to jsonb by the type
                        -- change below -- must be dropped first, then
                        -- reapplied as a jsonb-typed default afterward.
                        ALTER TABLE {$schemaName}.products ALTER COLUMN tags DROP DEFAULT;
                        ALTER TABLE {$schemaName}.products ALTER COLUMN tags TYPE JSONB USING to_jsonb(tags);
                        ALTER TABLE {$schemaName}.products ALTER COLUMN tags SET DEFAULT '[]';
                    END IF;
                END \$\$;
            ");
        } catch (\Throwable $e) {}
        // Part-number/SKU lookup (App\Support\SkuNormalizer applies the
        // identical rule at sync time; rag_service.py's SKU-detection path
        // applies it to whatever token it pulls from the question) — a
        // vector embedding of an alphanumeric string like "LM358N" carries
        // almost no useful signal, so an exact/near-exact match on this
        // column runs *before* falling back to hybrid retrieval.
        try {
            DB::statement("ALTER TABLE {$schemaName}.products ADD COLUMN IF NOT EXISTS sku_normalized VARCHAR(255)");
        } catch (\Throwable $e) {}
        try {
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_products_sku_normalized ON {$schemaName}.products(chatbot_id, sku_normalized)");
        } catch (\Throwable $e) {}
        // products.currency used to be CHAR(3) DEFAULT 'USD'. The plugin
        // sends the shop's real currency with every product, so that default
        // only ever applied when the value was missing — and then it stated
        // the wrong one rather than none, which is how an Iranian shop's
        // prices came out as USD. Dropped rather than changed to another
        // guess: the value belongs to the shop.
        try {
            DB::statement("ALTER TABLE {$schemaName}.products ALTER COLUMN currency DROP DEFAULT");
            DB::statement("ALTER TABLE {$schemaName}.products ALTER COLUMN currency TYPE VARCHAR(10)");
            // CHAR(3) padded every stored code; trim so comparisons and
            // display do not carry the padding forward.
            DB::statement("UPDATE {$schemaName}.products SET currency = NULLIF(BTRIM(currency), '')");
        } catch (\Throwable $e) {}
        // Tool calling — see createTenantTables()'s matching column comment.
        try {
            DB::statement("ALTER TABLE {$schemaName}.chatbots ADD COLUMN IF NOT EXISTS enabled_tools JSONB NOT NULL DEFAULT '[]'");
        } catch (\Throwable $e) {}
        // Authenticity fields — see createTenantTables()'s matching column
        // comments and rag_service._authenticity_rule().
        foreach (['authenticity_status', 'brand', 'official_distributor', 'warranty_period', 'country_of_origin'] as $col) {
            try {
                DB::statement("ALTER TABLE {$schemaName}.products ADD COLUMN IF NOT EXISTS {$col} VARCHAR(255)");
            } catch (\Throwable $e) {}
        }
        try {
            DB::statement("ALTER TABLE {$schemaName}.chatbots ADD COLUMN IF NOT EXISTS authenticity_unknown_message TEXT");
        } catch (\Throwable $e) {}
        // create_payment_link (doc-04) — NULL means the feature can't
        // actually create anything even if the tool is in enabled_tools;
        // see ChatController::createPaymentLink()'s own security checks.
        // A merchant must consciously set a real cap before this ever runs.
        try {
            DB::statement("ALTER TABLE {$schemaName}.chatbots ADD COLUMN IF NOT EXISTS max_payment_link_amount DECIMAL(14,4)");
        } catch (\Throwable $e) {}
        // Backfill: the ADD COLUMN above leaves every pre-existing chunk row
        // at content_tsv=NULL (never matches any full-text query), so hybrid
        // search would silently degrade to vector-only for already-embedded
        // content until this runs. Joins to documents.language the same way
        // embed.py picks the config for newly-embedded chunks.
        try {
            DB::statement("
                UPDATE {$schemaName}.chunks c
                SET content_tsv = to_tsvector(
                    CASE WHEN d.language = 'fa' THEN 'simple'::regconfig ELSE 'english'::regconfig END,
                    c.content
                )
                FROM {$schemaName}.documents d
                WHERE c.document_id = d.id AND c.content_tsv IS NULL
            ");
        } catch (\Throwable $e) {}
        // analytics_daily: written by AggregateAnalyticsJob, read by the
        // admin/customer dashboard widgets instead of them aggregating raw
        // messages/conversations on every page load.
        try {
            DB::statement("
                CREATE TABLE IF NOT EXISTS {$schemaName}.analytics_daily (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    chatbot_id UUID NOT NULL REFERENCES {$schemaName}.chatbots(id) ON DELETE CASCADE,
                    date DATE NOT NULL,
                    total_conversations BIGINT NOT NULL DEFAULT 0,
                    total_messages BIGINT NOT NULL DEFAULT 0,
                    user_messages BIGINT NOT NULL DEFAULT 0,
                    assistant_messages BIGINT NOT NULL DEFAULT 0,
                    total_tokens BIGINT NOT NULL DEFAULT 0,
                    prompt_tokens BIGINT NOT NULL DEFAULT 0,
                    completion_tokens BIGINT NOT NULL DEFAULT 0,
                    cost_toman DECIMAL(14,4) NOT NULL DEFAULT 0,
                    unique_visitors BIGINT NOT NULL DEFAULT 0,
                    avg_messages_per_conv DECIMAL(6,2) NOT NULL DEFAULT 0,
                    avg_response_latency_ms INTEGER,
                    fallback_count BIGINT NOT NULL DEFAULT 0,
                    unanswered_count BIGINT NOT NULL DEFAULT 0,
                    escalation_count BIGINT NOT NULL DEFAULT 0,
                    positive_feedback BIGINT NOT NULL DEFAULT 0,
                    negative_feedback BIGINT NOT NULL DEFAULT 0,
                    products_recommended BIGINT NOT NULL DEFAULT 0,
                    conversions BIGINT NOT NULL DEFAULT 0,
                    -- {intent: count} for that day (see intent_classifier.py
                    -- and the intent_classified conversation_event) --
                    -- doc-04's Intent analytics item, rolled up here the same
                    -- way products_recommended already is.
                    intent_counts JSONB NOT NULL DEFAULT '{}',
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, date)
                )
            ");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_analytics_daily_date ON {$schemaName}.analytics_daily(date)");
        } catch (\Throwable $e) {}
        // Pre-existing analytics_daily rows (shouldn't be any yet — the table
        // never existed until now, and the job was never scheduled — but this
        // is idempotent/harmless if it ever does need to run twice) get
        // cost_toman if the column somehow predates this fix.
        try {
            DB::statement("ALTER TABLE {$schemaName}.analytics_daily ADD COLUMN IF NOT EXISTS cost_toman DECIMAL(14,4) NOT NULL DEFAULT 0");
        } catch (\Throwable $e) {}
        try {
            DB::statement("ALTER TABLE {$schemaName}.analytics_daily ADD COLUMN IF NOT EXISTS unanswered_count BIGINT NOT NULL DEFAULT 0");
        } catch (\Throwable $e) {}
        try {
            DB::statement("ALTER TABLE {$schemaName}.analytics_daily ADD COLUMN IF NOT EXISTS intent_counts JSONB NOT NULL DEFAULT '{}'");
        } catch (\Throwable $e) {}
        // Fine-grained per-turn events — see createTenantTables()'s matching
        // block for the full rationale. Needed here too since this method is
        // what brings *existing* tenant schemas up to date, not just new
        // ones created after this feature shipped.
        try {
            DB::statement("
                CREATE TABLE IF NOT EXISTS {$schemaName}.conversation_events (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    conversation_id UUID NOT NULL REFERENCES {$schemaName}.conversations(id) ON DELETE CASCADE,
                    message_id UUID NULL REFERENCES {$schemaName}.messages(id) ON DELETE SET NULL,
                    chatbot_id UUID NOT NULL REFERENCES {$schemaName}.chatbots(id) ON DELETE CASCADE,
                    event_type VARCHAR(50) NOT NULL,
                    payload JSONB,
                    latency_ms INTEGER,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )
            ");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_conv_events_lookup ON {$schemaName}.conversation_events(chatbot_id, event_type, created_at)");
        } catch (\Throwable $e) {}
        // Lead capture — see createTenantTables()'s matching block.
        try {
            DB::statement("ALTER TABLE {$schemaName}.chatbots ADD COLUMN IF NOT EXISTS notification_settings JSONB NOT NULL DEFAULT '{}'");
        } catch (\Throwable $e) {}
        try {
            DB::statement("ALTER TABLE {$schemaName}.conversations ADD COLUMN IF NOT EXISTS pending_lead_question TEXT");
        } catch (\Throwable $e) {}
        try {
            DB::statement("
                CREATE TABLE IF NOT EXISTS {$schemaName}.leads (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    conversation_id UUID NOT NULL REFERENCES {$schemaName}.conversations(id) ON DELETE CASCADE,
                    chatbot_id UUID NOT NULL REFERENCES {$schemaName}.chatbots(id) ON DELETE CASCADE,
                    name VARCHAR(255),
                    contact VARCHAR(255) NOT NULL,
                    contact_type VARCHAR(10) NOT NULL,
                    question TEXT,
                    status VARCHAR(20) NOT NULL DEFAULT 'new',
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )
            ");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_leads_lookup ON {$schemaName}.leads(chatbot_id, status, created_at)");
        } catch (\Throwable $e) {}
        // out_of_stock / not_in_catalog lead modes: without the item name on
        // the row itself, a merchant reading the lead list has no idea what
        // to call the person back about.
        try {
            DB::statement("ALTER TABLE {$schemaName}.leads ADD COLUMN IF NOT EXISTS requested_item VARCHAR(255)");
            DB::statement("ALTER TABLE {$schemaName}.leads ADD COLUMN IF NOT EXISTS type VARCHAR(30) NOT NULL DEFAULT 'unanswered'");
            DB::statement("ALTER TABLE {$schemaName}.conversations ADD COLUMN IF NOT EXISTS pending_lead_type VARCHAR(30)");
            DB::statement("ALTER TABLE {$schemaName}.conversations ADD COLUMN IF NOT EXISTS pending_lead_item VARCHAR(255)");
            // The waitlist: an exact product id makes a restock match exact
            // instead of a name comparison, and request_status is the
            // merchant's own open/fulfilled/rejected axis, separate from
            // the lead's sales status.
            DB::statement("ALTER TABLE {$schemaName}.leads ADD COLUMN IF NOT EXISTS requested_product_id BIGINT");
            DB::statement("ALTER TABLE {$schemaName}.leads ADD COLUMN IF NOT EXISTS request_status VARCHAR(20) NOT NULL DEFAULT 'open'");
            DB::statement("ALTER TABLE {$schemaName}.conversations ADD COLUMN IF NOT EXISTS pending_lead_product_id BIGINT");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_leads_waitlist ON {$schemaName}.leads(chatbot_id, type, request_status)");
        } catch (\Throwable $e) {}
        // Revenue attribution (doc-04, prerequisite for Intent analytics) —
        // see createTenantTables()'s matching block and SyncService::
        // recordOrder(). conversation_id is nullable and only ever set when
        // the WordPress plugin's order webhook found a real haman_conv_id
        // cookie from the SAME browser session that placed the order — a
        // real signal, never guessed. UNIQUE(chatbot_id, woo_order_id) makes
        // recordOrder() naturally idempotent against WooCommerce re-firing
        // the same order-placed hook (e.g. a thank-you page refresh).
        try {
            DB::statement("
                CREATE TABLE IF NOT EXISTS {$schemaName}.orders (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    chatbot_id UUID NOT NULL REFERENCES {$schemaName}.chatbots(id) ON DELETE CASCADE,
                    conversation_id UUID NULL REFERENCES {$schemaName}.conversations(id) ON DELETE SET NULL,
                    woo_order_id BIGINT NOT NULL,
                    total DECIMAL(14,4) NOT NULL DEFAULT 0,
                    currency VARCHAR(10) NOT NULL DEFAULT 'IRT',
                    status VARCHAR(30) NOT NULL DEFAULT 'pending',
                    line_items JSONB NOT NULL DEFAULT '[]',
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, woo_order_id)
                )
            ");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_orders_conv ON {$schemaName}.orders(conversation_id)");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_orders_lookup ON {$schemaName}.orders(chatbot_id, created_at)");
        } catch (\Throwable $e) {}
        // doc-07's "actions" item: rule-derived, zero-cost suggestions.
        // fingerprint is what makes a dismissal stick — the daily job
        // recomputes the same finding with a fresh count and must update
        // that row rather than resurrect it as a new one.
        try {
            DB::statement("
                CREATE TABLE IF NOT EXISTS {$schemaName}.suggestions (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    chatbot_id UUID NOT NULL REFERENCES {$schemaName}.chatbots(id) ON DELETE CASCADE,
                    type VARCHAR(50) NOT NULL,
                    fingerprint VARCHAR(255) NOT NULL,
                    params JSONB NOT NULL DEFAULT '{}',
                    count INTEGER NOT NULL DEFAULT 0,
                    source_conversation_ids JSONB NOT NULL DEFAULT '[]',
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    computed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    dismissed_at TIMESTAMPTZ,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, fingerprint)
                )
            ");
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$schemaName}_suggestions_active ON {$schemaName}.suggestions(chatbot_id, status, count DESC)");
        } catch (\Throwable $e) {}
        // create_payment_link (doc-04) — true only for an order Laravel
        // itself created via ChatController::createPaymentLink(); an order
        // that merely got cookie-attributed after a normal checkout (the
        // order.placed/on_order_placed() flow) stays false. This is what
        // the customer portal's "Chat orders" page filters on.
        try {
            DB::statement("ALTER TABLE {$schemaName}.orders ADD COLUMN IF NOT EXISTS created_by_bot BOOLEAN NOT NULL DEFAULT false");
        } catch (\Throwable $e) {}
    }

    private function createTenantTables(string $s): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.chatbots (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                name VARCHAR(255) NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'support',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                -- Distinct from name (the bot's own persona/display name,
                -- e.g. Sales Bot) -- this is the actual business the bot
                -- speaks on behalf of, injected verbatim into the LLM prompt
                -- so it can answer a company-name question correctly instead
                -- of guessing from scraped content. Nullable: when unset,
                -- the prompt's grounding rules tell the model to say it
                -- does not know rather than invent one.
                business_name VARCHAR(255),
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
                rerank_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.500,
                memory_window SMALLINT NOT NULL DEFAULT 6,
                widget_config JSONB NOT NULL DEFAULT '{}',
                -- Per-channel (email/telegram/webhook) alert preferences
                -- for lead_captured/unanswered events, keyed by channel
                -- name -- see NotificationService for the exact shape of
                -- each channel's settings (enabled flag, destination
                -- address/token, which events, digest mode). A separate
                -- column from widget_config on purpose: that one is
                -- client/widget-facing behavior, this is merchant-facing
                -- alerting config, never sent to the browser.
                notification_settings JSONB NOT NULL DEFAULT '{}',
                -- Which tool-registry tools (see python-ai-service/app/
                -- services/tools/registry.py) this chatbot may call, e.g.
                -- a JSON array containing e.g. get_product_availability.
                -- Empty by default — opt-in per chatbot, same posture as
                -- lead_capture_enabled:
                -- every existing chatbot keeps today's retrieval-only
                -- behavior unchanged unless the merchant explicitly turns
                -- a tool on. A separate column from widget_config (that one
                -- is client/widget-facing UI text; this gates a real
                -- server-side capability with live-data and cost
                -- implications, never sent to the browser).
                enabled_tools JSONB NOT NULL DEFAULT '[]',
                -- Store-level fallback for is-this-genuine questions when a
                -- product has none of the 5 authenticity fields synced.
                -- Nullable -- when unset, rag_service._authenticity_rule()
                -- uses its own hardcoded bilingual default instead.
                authenticity_unknown_message TEXT,
                -- create_payment_link (doc-04) -- NULL means the feature
                -- can't actually create anything even if the tool is in
                -- enabled_tools; see ChatController::createPaymentLink().
                max_payment_link_amount DECIMAL(14,4),
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
                -- Populated explicitly by the Python embedding pipeline
                -- (embed.py) at insert time via to_tsvector(config, content),
                -- not a Postgres GENERATED column: to_tsvector's regconfig
                -- argument must vary per row (documents.language: 'simple'
                -- for fa — Postgres has no Persian stemmer — vs 'english'),
                -- and GENERATED ALWAYS AS requires an IMMUTABLE expression,
                -- which rules out a per-row CASE-selected regconfig.
                content_tsv TSVECTOR,
                metadata JSONB NOT NULL DEFAULT '{}',
                token_count SMALLINT NOT NULL DEFAULT 0,
                embedding_model VARCHAR(100) NOT NULL DEFAULT 'text-embedding-004',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_chunks_chatbot ON {$s}.chunks(chatbot_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_chunks_tsv ON {$s}.chunks USING GIN(content_tsv)");

        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.products (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                woo_product_id BIGINT NOT NULL,
                name VARCHAR(500) NOT NULL,
                slug VARCHAR(500),
                sku VARCHAR(255),
                -- App\Support\SkuNormalizer applies the same rule
                -- (uppercase, strip whitespace/hyphens) that
                -- rag_service.py's SKU-detection path applies to the token
                -- it pulls from an incoming question — an exact/near-exact
                -- match here runs before hybrid retrieval even starts.
                sku_normalized VARCHAR(255),
                type VARCHAR(30) DEFAULT 'simple',
                status VARCHAR(20) DEFAULT 'publish',
                description TEXT,
                short_description TEXT,
                price DECIMAL(12,4),
                regular_price DECIMAL(12,4),
                sale_price DECIMAL(12,4),
                -- No default, and deliberately not USD. The WordPress
                -- plugin sends the shop's own get_woocommerce_currency() with
                -- every product, so a row with no currency means the value
                -- genuinely did not arrive -- which must read as unknown, not
                -- be quietly filled in with the wrong one. An Iranian shop's
                -- prices were being shown to customers as USD.
                -- Wider than CHAR(3) because WooCommerce currency codes are
                -- not all three characters, and CHAR pads what it stores.
                currency VARCHAR(10),
                stock_status VARCHAR(20) DEFAULT 'instock',
                stock_quantity INTEGER,
                average_rating DECIMAL(3,2),
                review_count INTEGER DEFAULT 0,
                permalink TEXT,
                featured_image TEXT,
                attributes JSONB DEFAULT '{}',
                -- Not TEXT[] (Postgres native array) -- Product's tags
                -- Eloquent cast JSON-encodes PHP arrays (the same cast
                -- attributes above uses), which produces a JSON string that
                -- Postgres cannot parse as a text[] literal (malformed
                -- array literal). JSONB accepts what the cast actually
                -- sends, matching attributes.
                tags JSONB DEFAULT '[]',
                -- Is this genuine? -- the most common customer question in
                -- both real interviews this was built from. Seller-entered
                -- data ONLY (synced from a WordPress admin-configured field
                -- mapping -- see Haman_Product_Sync::authenticity_fields()),
                -- never something the model infers; a NULL here must always
                -- read as not-recorded, never as a genuine/not-genuine
                -- verdict. See rag_service._authenticity_rule().
                authenticity_status VARCHAR(255),
                brand VARCHAR(255),
                official_distributor VARCHAR(255),
                warranty_period VARCHAR(255),
                country_of_origin VARCHAR(255),
                embedding_status VARCHAR(20) DEFAULT 'pending',
                synced_at TIMESTAMPTZ DEFAULT now(),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE(chatbot_id, woo_product_id)
            )
        ");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_products_sku_normalized ON {$s}.products(chatbot_id, sku_normalized)");

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
                -- Set to the user's own question text the moment the bot
                -- asks them for contact info instead of answering (see
                -- LeadCaptureService) -- the next incoming message on this
                -- conversation is then read as a phone/email attempt
                -- instead of a new question, not run through RAG at all.
                -- Cleared once resolved (lead captured or an invalid
                -- attempt exhausted the flow).
                pending_lead_question TEXT,
                -- Which flow armed the pending question, and what the
                -- customer asked for — both have to survive to the next
                -- turn, since that is when the lead row is written.
                pending_lead_type VARCHAR(30),
                pending_lead_item VARCHAR(255),
                pending_lead_product_id BIGINT,
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
                cost_toman DECIMAL(14,4) NOT NULL DEFAULT 0,
                model_used VARCHAR(100),
                latency_ms INTEGER,
                is_fallback BOOLEAN DEFAULT false,
                is_unanswered BOOLEAN NOT NULL DEFAULT false,
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

        // Written by AggregateAnalyticsJob (routes/console.php's
        // haman:aggregate-analytics, scheduled daily) — dashboard widgets
        // read from here instead of aggregating raw messages/conversations
        // on every page load. unanswered_count backs the demand-gap /
        // product-health signal on both the admin and customer dashboards.
        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.analytics_daily (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                date DATE NOT NULL,
                total_conversations BIGINT NOT NULL DEFAULT 0,
                total_messages BIGINT NOT NULL DEFAULT 0,
                user_messages BIGINT NOT NULL DEFAULT 0,
                assistant_messages BIGINT NOT NULL DEFAULT 0,
                total_tokens BIGINT NOT NULL DEFAULT 0,
                prompt_tokens BIGINT NOT NULL DEFAULT 0,
                completion_tokens BIGINT NOT NULL DEFAULT 0,
                cost_toman DECIMAL(14,4) NOT NULL DEFAULT 0,
                unique_visitors BIGINT NOT NULL DEFAULT 0,
                avg_messages_per_conv DECIMAL(6,2) NOT NULL DEFAULT 0,
                avg_response_latency_ms INTEGER,
                fallback_count BIGINT NOT NULL DEFAULT 0,
                unanswered_count BIGINT NOT NULL DEFAULT 0,
                escalation_count BIGINT NOT NULL DEFAULT 0,
                positive_feedback BIGINT NOT NULL DEFAULT 0,
                negative_feedback BIGINT NOT NULL DEFAULT 0,
                products_recommended BIGINT NOT NULL DEFAULT 0,
                conversions BIGINT NOT NULL DEFAULT 0,
                intent_counts JSONB NOT NULL DEFAULT '{}',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE(chatbot_id, date)
            )
        ");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_analytics_daily_date ON {$s}.analytics_daily(date)");

        // Fine-grained per-turn events (conversation_started, retrieval,
        // response, unanswered, product_mentioned, feedback, lead_captured,
        // and — once built — escalation) — what AggregateAnalyticsJob
        // actually rolls up into analytics_daily's escalation_count/
        // positive_feedback/negative_feedback/products_recommended/
        // conversions columns, all of which sat permanently at 0 with
        // nothing ever writing them. payload holds event-specific detail
        // (query text, chunk_ids, scores, tokens, etc.) as JSONB rather
        // than a fixed column per event type, since the event set is
        // still growing; message_id is nullable because retrieval/
        // response/unanswered events fire from the Python RAG service
        // before the assistant Message row exists yet.
        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.conversation_events (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                conversation_id UUID NOT NULL REFERENCES {$s}.conversations(id) ON DELETE CASCADE,
                message_id UUID NULL REFERENCES {$s}.messages(id) ON DELETE SET NULL,
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                event_type VARCHAR(50) NOT NULL,
                payload JSONB,
                latency_ms INTEGER,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_conv_events_lookup ON {$s}.conversation_events(chatbot_id, event_type, created_at)");

        // A missed answer with no way to reach the visitor back is a lost
        // sale for a B2B/high-ticket shop, not just an unanswered-rate
        // number on a dashboard — see LeadCaptureService, which is what
        // actually populates this table when the bot asks for (and gets) a
        // phone/email instead of just saying "I don't know".
        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.leads (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                conversation_id UUID NOT NULL REFERENCES {$s}.conversations(id) ON DELETE CASCADE,
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                name VARCHAR(255),
                contact VARCHAR(255) NOT NULL,
                contact_type VARCHAR(10) NOT NULL,
                question TEXT,
                -- What the customer actually wanted, when the lead came
                -- from an out-of-stock or not-stocked moment. Without it
                -- the merchant knows someone wants a callback but not
                -- what about.
                requested_item VARCHAR(255),
                -- Known only for an out-of-stock request: lets a restock be
                -- matched exactly rather than by comparing product names.
                requested_product_id BIGINT,
                -- unanswered | volunteered | out_of_stock | not_in_catalog
                type VARCHAR(30) NOT NULL DEFAULT 'unanswered',
                -- The merchant's own handling of the request (open |
                -- fulfilled | rejected), deliberately separate from the
                -- lead's sales status.
                request_status VARCHAR(20) NOT NULL DEFAULT 'open',
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_leads_lookup ON {$s}.leads(chatbot_id, status, created_at)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_leads_waitlist ON {$s}.leads(chatbot_id, type, request_status)");

        // Revenue attribution (doc-04, prerequisite for Intent analytics) —
        // see fixSchema()'s matching block for the full rationale on
        // conversation_id and the UNIQUE(chatbot_id, woo_order_id) idempotency
        // guarantee.
        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.orders (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                conversation_id UUID NULL REFERENCES {$s}.conversations(id) ON DELETE SET NULL,
                woo_order_id BIGINT NOT NULL,
                total DECIMAL(14,4) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'IRT',
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                line_items JSONB NOT NULL DEFAULT '[]',
                -- create_payment_link (doc-04) -- true only for an order
                -- Laravel itself created via ChatController::
                -- createPaymentLink(); an order merely cookie-attributed
                -- after a normal checkout stays false.
                created_by_bot BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE(chatbot_id, woo_order_id)
            )
        ");
        DB::statement("
            CREATE TABLE IF NOT EXISTS {$s}.suggestions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                chatbot_id UUID NOT NULL REFERENCES {$s}.chatbots(id) ON DELETE CASCADE,
                type VARCHAR(50) NOT NULL,
                -- Stable identity for one finding across daily recomputes,
                -- so dismissing it dismisses it for good (doc-07).
                fingerprint VARCHAR(255) NOT NULL,
                params JSONB NOT NULL DEFAULT '{}',
                count INTEGER NOT NULL DEFAULT 0,
                source_conversation_ids JSONB NOT NULL DEFAULT '[]',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                computed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                dismissed_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE(chatbot_id, fingerprint)
            )
        ");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_orders_conv ON {$s}.orders(conversation_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_orders_lookup ON {$s}.orders(chatbot_id, created_at)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_{$s}_suggestions_active ON {$s}.suggestions(chatbot_id, status, count DESC)");

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
