<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// One-time grant, by explicit instruction — not a general-purpose seeding
// mechanism, so this is intentionally a single hardcoded email rather than
// an env var or config value: is_platform_admin must never be something a
// deploy-time config file can silently grant to a different address.
return new class extends Migration {
    public function up(): void {
        DB::table('users')->where('email', 'reza@hamman.ir')->update(['is_platform_admin' => true]);
    }
    public function down(): void {
        DB::table('users')->where('email', 'reza@hamman.ir')->update(['is_platform_admin' => false]);
    }
};
