<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Admin-defined catalog: what a new chatbot of a given type costs to
        // buy/renew. chatbot_index.monthly_price_toman (per-instance) still
        // wins once a chatbot exists — this only supplies the default when a
        // customer self-purchases a brand new one of this type.
        Schema::create('chatbot_type_prices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type')->unique(); // matches App\Enums\ChatbotType values
            $t->string('name');
            $t->bigInteger('price_toman')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('token_packages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            // Null = applies to any chatbot type; set = only offered for that type.
            $t->string('chatbot_type')->nullable();
            $t->bigInteger('token_amount');
            $t->bigInteger('price_toman');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->foreign('tenant_id')->references('id')->on('tenants');
            $t->uuid('chatbot_id')->nullable(); // optional: ticket about one specific chatbot
            $t->string('subject');
            $t->string('status')->default('open'); // open | answered | closed
            $t->string('priority')->default('normal'); // low | normal | high
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('ticket_id');
            $t->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $t->string('sender_type'); // customer | admin
            $t->uuid('sender_id')->nullable();
            $t->text('body');
            $t->timestamps();
            $t->index('ticket_id');
        });
    }
    public function down(): void {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('token_packages');
        Schema::dropIfExists('chatbot_type_prices');
    }
};
