<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A JSONB bag for everything the settings page grew to hold.
 *
 * The existing eight columns stay exactly where they are: ZarinpalService and
 * SmsService read them, Python reads some of them, and a column rename is a
 * migration risk with no upside. SettingsRegistry maps those keys onto their
 * columns and everything new into this bag, so the admin page sees one
 * uniform list either way.
 *
 * Only values that differ from the declared default are stored here. That is
 * what makes "reset to default" a delete rather than a guess, and what lets a
 * default change in code actually reach installations that never touched it.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('platform_settings', 'values')) {
                $t->jsonb('values')->nullable();
            }
        });
    }

    public function down(): void {
        Schema::table('platform_settings', function (Blueprint $t) {
            $t->dropColumn('values');
        });
    }
};
