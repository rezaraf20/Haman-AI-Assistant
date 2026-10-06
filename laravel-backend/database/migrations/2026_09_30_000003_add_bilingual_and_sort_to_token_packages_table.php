<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('token_packages', function (Blueprint $t) {
            if (!Schema::hasColumn('token_packages', 'name_en')) {
                $t->string('name_en', 255)->nullable()->after('name');
            }
            if (!Schema::hasColumn('token_packages', 'bonus_percent')) {
                $t->decimal('bonus_percent', 5, 2)->nullable()->after('token_amount');
            }
            if (!Schema::hasColumn('token_packages', 'sort_order')) {
                $t->smallInteger('sort_order')->default(0)->after('is_active');
            }
        });
    }

    public function down(): void {
        Schema::table('token_packages', function (Blueprint $t) {
            $t->dropColumn(['name_en', 'bonus_percent', 'sort_order']);
        });
    }
};
