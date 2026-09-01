<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Enable extensions
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('plans', function (Blueprint $t) {
            $t->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $t->string('name', 100);
            $t->string('slug', 100)->unique();
            $t->text('description')->nullable();
            $t->decimal('price_monthly', 10, 2)->default(0);
            $t->decimal('price_yearly', 10, 2)->default(0);
            $t->smallInteger('max_chatbots')->default(1);
            $t->bigInteger('max_tokens_monthly')->default(100000);
            $t->integer('max_documents')->default(50);
            $t->bigInteger('max_messages_monthly')->default(1000);
            $t->smallInteger('max_domains')->default(1);
            $t->string('model_tier', 50)->default('gemini-1.5-flash');
            $t->jsonb('features')->default('{}');
            $t->boolean('is_active')->default(true);
            $t->smallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('plans'); }
};
