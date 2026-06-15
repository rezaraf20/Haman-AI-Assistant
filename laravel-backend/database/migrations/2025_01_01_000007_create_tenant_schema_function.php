<?php
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void {
        \DB::unprepared("
        CREATE OR REPLACE FUNCTION create_tenant_schema(schema_name TEXT)
        RETURNS void AS \$\$
        BEGIN
            EXECUTE format('CREATE SCHEMA IF NOT EXISTS %I', schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.chatbots (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    name VARCHAR(255) NOT NULL,
                    type VARCHAR(30) NOT NULL DEFAULT ''support'',
                    status VARCHAR(20) NOT NULL DEFAULT ''active'',
                    system_prompt TEXT,
                    welcome_message TEXT,
                    fallback_response TEXT,
                    escalation_message TEXT,
                    embedding_model VARCHAR(100) NOT NULL DEFAULT ''text-embedding-3-small'',
                    llm_model VARCHAR(100) NOT NULL DEFAULT ''gpt-4o-mini'',
                    temperature DECIMAL(3,2) NOT NULL DEFAULT 0.30,
                    max_tokens_response SMALLINT NOT NULL DEFAULT 800,
                    retrieval_top_k SMALLINT NOT NULL DEFAULT 8,
                    retrieval_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.700,
                    reranker_enabled BOOLEAN NOT NULL DEFAULT false,
                    memory_window SMALLINT NOT NULL DEFAULT 6,
                    widget_config JSONB NOT NULL DEFAULT ''{}'',
                    language VARCHAR(10) NOT NULL DEFAULT ''en'',
                    response_language VARCHAR(10) NOT NULL DEFAULT ''auto'',
                    is_active BOOLEAN NOT NULL DEFAULT true,
                    total_conversations BIGINT NOT NULL DEFAULT 0,
                    total_messages BIGINT NOT NULL DEFAULT 0,
                    total_tokens_used BIGINT NOT NULL DEFAULT 0,
                    last_trained_at TIMESTAMPTZ,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )', schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.chatbot_domains (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    domain VARCHAR(255) NOT NULL,
                    is_active BOOLEAN NOT NULL DEFAULT true,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, domain)
                )', schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.prompt_templates (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    version SMALLINT NOT NULL DEFAULT 1,
                    name VARCHAR(255) NOT NULL,
                    system_prompt TEXT NOT NULL,
                    variables JSONB NOT NULL DEFAULT ''{}'',
                    is_active BOOLEAN NOT NULL DEFAULT false,
                    performance_score DECIMAL(5,4),
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )', schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.documents (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    source_type VARCHAR(30) NOT NULL,
                    source_url TEXT,
                    external_id VARCHAR(255),
                    title VARCHAR(500) NOT NULL,
                    raw_content TEXT NOT NULL,
                    processed_content TEXT,
                    content_hash CHAR(64) NOT NULL,
                    language VARCHAR(10) NOT NULL DEFAULT ''en'',
                    metadata JSONB NOT NULL DEFAULT ''{}'',
                    status VARCHAR(20) NOT NULL DEFAULT ''pending'',
                    chunk_count SMALLINT NOT NULL DEFAULT 0,
                    token_count INTEGER NOT NULL DEFAULT 0,
                    error_message TEXT,
                    retry_count SMALLINT NOT NULL DEFAULT 0,
                    last_synced_at TIMESTAMPTZ,
                    indexed_at TIMESTAMPTZ,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, source_type, external_id)
                )', schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.chunks (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    document_id UUID NOT NULL REFERENCES %I.documents(id) ON DELETE CASCADE,
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    chunk_index SMALLINT NOT NULL,
                    content TEXT NOT NULL,
                    embedding vector(768),
                    metadata JSONB NOT NULL DEFAULT ''{}'',
                    token_count SMALLINT NOT NULL DEFAULT 0,
                    embedding_model VARCHAR(100) NOT NULL DEFAULT ''text-embedding-3-small'',
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )', schema_name, schema_name);

            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_%s_chunks_chatbot ON %I.chunks(chatbot_id)',
                replace(schema_name,'tenant_',''), schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.products (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    woo_product_id BIGINT NOT NULL,
                    name VARCHAR(500) NOT NULL,
                    slug VARCHAR(500),
                    sku VARCHAR(255),
                    type VARCHAR(30) DEFAULT ''simple'',
                    status VARCHAR(20) DEFAULT ''publish'',
                    description TEXT,
                    short_description TEXT,
                    price DECIMAL(12,4),
                    regular_price DECIMAL(12,4),
                    sale_price DECIMAL(12,4),
                    currency CHAR(3) DEFAULT ''USD'',
                    stock_status VARCHAR(20) DEFAULT ''instock'',
                    stock_quantity INTEGER,
                    average_rating DECIMAL(3,2),
                    review_count INTEGER DEFAULT 0,
                    permalink TEXT,
                    featured_image TEXT,
                    attributes JSONB DEFAULT ''{}'',
                    tags TEXT[] DEFAULT ''{}'',
                    embedding_status VARCHAR(20) DEFAULT ''pending'',
                    synced_at TIMESTAMPTZ DEFAULT now(),
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, woo_product_id)
                )', schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.faqs (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    document_id UUID REFERENCES %I.documents(id),
                    question TEXT NOT NULL,
                    answer TEXT NOT NULL,
                    category VARCHAR(255),
                    source VARCHAR(30) DEFAULT ''manual'',
                    language VARCHAR(10) DEFAULT ''en'',
                    is_active BOOLEAN DEFAULT true,
                    view_count INTEGER DEFAULT 0,
                    helpful_count INTEGER DEFAULT 0,
                    sort_order SMALLINT DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )', schema_name, schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.conversations (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    session_id VARCHAR(128) NOT NULL,
                    visitor_id VARCHAR(128),
                    status VARCHAR(20) NOT NULL DEFAULT ''active'',
                    channel VARCHAR(30) DEFAULT ''widget'',
                    language VARCHAR(10) DEFAULT ''en'',
                    page_url TEXT,
                    referrer TEXT,
                    utm_source VARCHAR(255),
                    utm_medium VARCHAR(255),
                    utm_campaign VARCHAR(255),
                    ip_country CHAR(2),
                    device_type VARCHAR(20),
                    browser VARCHAR(50),
                    message_count SMALLINT DEFAULT 0,
                    user_message_count SMALLINT DEFAULT 0,
                    total_tokens INTEGER DEFAULT 0,
                    satisfaction_score SMALLINT,
                    is_converted BOOLEAN DEFAULT false,
                    escalated_at TIMESTAMPTZ,
                    ended_at TIMESTAMPTZ,
                    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, session_id)
                )', schema_name, schema_name);

            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_%s_conv_chatbot ON %I.conversations(chatbot_id,started_at DESC)',
                replace(schema_name,'tenant_',''), schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.messages (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    conversation_id UUID NOT NULL REFERENCES %I.conversations(id) ON DELETE CASCADE,
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    role VARCHAR(20) NOT NULL,
                    content TEXT NOT NULL,
                    retrieved_chunk_ids UUID[] DEFAULT ''{}'',
                    retrieval_scores DECIMAL[] DEFAULT ''{}'',
                    prompt_tokens INTEGER DEFAULT 0,
                    completion_tokens INTEGER DEFAULT 0,
                    total_tokens INTEGER DEFAULT 0,
                    model_used VARCHAR(100),
                    latency_ms INTEGER,
                    finish_reason VARCHAR(30),
                    is_fallback BOOLEAN DEFAULT false,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )', schema_name, schema_name, schema_name);

            EXECUTE format('CREATE INDEX IF NOT EXISTS idx_%s_msg_conv ON %I.messages(conversation_id,created_at ASC)',
                replace(schema_name,'tenant_',''), schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.message_feedback (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    message_id UUID NOT NULL UNIQUE REFERENCES %I.messages(id) ON DELETE CASCADE,
                    chatbot_id UUID NOT NULL,
                    rating SMALLINT NOT NULL CHECK(rating IN (1,-1)),
                    comment TEXT,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )', schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.sync_jobs (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    job_type VARCHAR(30) NOT NULL,
                    triggered_by VARCHAR(30) DEFAULT ''plugin'',
                    status VARCHAR(20) NOT NULL DEFAULT ''queued'',
                    priority SMALLINT DEFAULT 5,
                    items_total INTEGER DEFAULT 0,
                    items_processed INTEGER DEFAULT 0,
                    items_failed INTEGER DEFAULT 0,
                    error_log JSONB DEFAULT ''[]'',
                    payload JSONB DEFAULT ''{}'',
                    result JSONB,
                    retry_count SMALLINT DEFAULT 0,
                    next_retry_at TIMESTAMPTZ,
                    worker_id VARCHAR(255),
                    started_at TIMESTAMPTZ,
                    completed_at TIMESTAMPTZ,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )', schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.webhook_logs (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID REFERENCES %I.chatbots(id),
                    event VARCHAR(100) NOT NULL,
                    payload JSONB NOT NULL,
                    signature_valid BOOLEAN DEFAULT false,
                    status VARCHAR(20) DEFAULT ''received'',
                    processing_error TEXT,
                    sync_job_id UUID,
                    source_ip INET,
                    received_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )', schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.chatbot_integrations (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    integration_type VARCHAR(50) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    config JSONB DEFAULT ''{}'',
                    credentials JSONB DEFAULT ''{}'',
                    is_active BOOLEAN DEFAULT true,
                    last_tested_at TIMESTAMPTZ,
                    test_status VARCHAR(20) DEFAULT ''pending'',
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, integration_type)
                )', schema_name, schema_name);

            EXECUTE format('
                CREATE TABLE IF NOT EXISTS %I.analytics_daily (
                    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
                    chatbot_id UUID NOT NULL REFERENCES %I.chatbots(id) ON DELETE CASCADE,
                    date DATE NOT NULL,
                    total_conversations INTEGER DEFAULT 0,
                    total_messages INTEGER DEFAULT 0,
                    user_messages INTEGER DEFAULT 0,
                    assistant_messages INTEGER DEFAULT 0,
                    total_tokens BIGINT DEFAULT 0,
                    prompt_tokens BIGINT DEFAULT 0,
                    completion_tokens BIGINT DEFAULT 0,
                    unique_visitors INTEGER DEFAULT 0,
                    avg_messages_per_conv DECIMAL(5,2) DEFAULT 0,
                    avg_response_latency_ms INTEGER DEFAULT 0,
                    fallback_count INTEGER DEFAULT 0,
                    escalation_count INTEGER DEFAULT 0,
                    positive_feedback INTEGER DEFAULT 0,
                    negative_feedback INTEGER DEFAULT 0,
                    products_recommended INTEGER DEFAULT 0,
                    conversions INTEGER DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    UNIQUE(chatbot_id, date)
                )', schema_name, schema_name);

        END;
        \$\$ LANGUAGE plpgsql;

        -- Auto-update trigger function
        CREATE OR REPLACE FUNCTION update_updated_at()
        RETURNS TRIGGER AS \$\$
        BEGIN NEW.updated_at = now(); RETURN NEW; END;
        \$\$ LANGUAGE plpgsql;
        ");
    }
    public function down(): void {
        \DB::unprepared("DROP FUNCTION IF EXISTS create_tenant_schema(TEXT)");
        \DB::unprepared("DROP FUNCTION IF EXISTS update_updated_at()");
    }
};
