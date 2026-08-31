<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer-portal signup is moving to phone+SMS-OTP instead of email+password.
// Nullable throughout: existing (admin-created) users have none of this and
// keep working via email/password on the /admin panel, which is untouched.
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone', 20)->nullable()->unique()->after('email');
            $t->string('first_name')->nullable()->after('phone');
            $t->string('last_name')->nullable()->after('first_name');
            $t->string('national_id', 20)->nullable()->after('last_name');
            $t->text('address')->nullable()->after('national_id');
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['phone', 'first_name', 'last_name', 'national_id', 'address']);
        });
    }
};
