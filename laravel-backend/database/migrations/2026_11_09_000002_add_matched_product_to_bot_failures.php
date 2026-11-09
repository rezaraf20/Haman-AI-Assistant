<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bot_failures.matched_product — for a "we don't have it" reply, the
 * catalogue product the question matched (App\Support\CatalogMatch). Set on
 * missed_product rows (the shop had it in stock: our failure) and on
 * not_carried rows whose only match is out of stock (her restock signal).
 * not_carried rows are kept here too, so the rate "said we don't have it
 * while we did" can be read across all shops.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('bot_failures', function (Blueprint $table) {
            $table->string('matched_product', 500)->nullable();
            $table->index(['kind', 'occurred_at']);
        });
    }

    public function down(): void {
        Schema::table('bot_failures', function (Blueprint $table) {
            $table->dropIndex(['kind', 'occurred_at']);
            $table->dropColumn('matched_product');
        });
    }
};
