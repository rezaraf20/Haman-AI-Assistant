<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Plans and chatbot-type prices only ever had one `name` column, so an
 * admin who typed a Persian name (the natural thing to do, running a
 * Persian-language panel) made it show up on the English landing page too.
 * `name_en`/`description_en` are optional siblings — null falls back to the
 * existing column, so nothing already saved changes until someone
 * deliberately fills the English side in. See Plan::getDisplayNameAttribute()
 * / ChatbotTypePrice::getDisplayNameAttribute().
 *
 * `features` is reset to `[]` here rather than left alone: every existing
 * row holds `{"woocommerce": true|false}` — PlanSeeder using this column as
 * an internal capability flag nobody ever actually reads (grepped: nothing
 * checks $plan->features['woocommerce']) instead of the display list its
 * own form field / help text always claimed it was. Foreach-ing that flag
 * printed a bare "1" (PHP's string cast of `true`) as the last bullet on
 * every paid plan's public pricing card. Clearing it is safe precisely
 * because nothing reads it; the admin gets a clean slate to add real
 * bilingual bullets through the new Repeater field.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('plans', function (Blueprint $t) {
            if (!Schema::hasColumn('plans', 'name_en')) {
                $t->string('name_en', 100)->nullable()->after('name');
            }
            if (!Schema::hasColumn('plans', 'description_en')) {
                $t->text('description_en')->nullable()->after('description');
            }
        });

        Schema::table('chatbot_type_prices', function (Blueprint $t) {
            if (!Schema::hasColumn('chatbot_type_prices', 'name_en')) {
                $t->string('name_en')->nullable()->after('name');
            }
        });

        DB::table('plans')->update(['features' => '[]']);
    }

    public function down(): void {
        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn(['name_en', 'description_en']);
        });
        Schema::table('chatbot_type_prices', function (Blueprint $t) {
            $t->dropColumn('name_en');
        });
    }
};
