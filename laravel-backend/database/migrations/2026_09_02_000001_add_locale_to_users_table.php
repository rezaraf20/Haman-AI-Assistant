<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs SetLocale middleware — a logged-in user's saved preference wins over
// Accept-Language. Defaults to 'fa' (this product's primary market) rather
// than following config('app.locale'), so existing rows don't need a backfill.
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->string('locale', 5)->default('fa')->after('preferences');
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('locale');
        });
    }
};
