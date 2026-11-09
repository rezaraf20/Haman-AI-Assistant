<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transfer is marked "reorged" only on positive evidence now: the
 * canonical chain's hash at its block height is different from the one it
 * was seen in, and that hash is kept in reorg_evidence. A receipt nobody
 * returns (a lagging replica of a load-balanced public RPC) is only a
 * retry — counted in verify_attempts — and past the limit the transfer goes
 * to manual review (status "stuck"), never to "reorged".
 *
 * Status values from here on: detected | credited | unmatched | stuck |
 * reorged | ignored.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('crypto_transfers', function (Blueprint $t) {
            $t->unsignedInteger('verify_attempts')->default(0)->after('status');
            $t->string('reorg_evidence', 200)->nullable()->after('verify_attempts');
        });
    }

    public function down(): void {
        Schema::table('crypto_transfers', fn (Blueprint $t) => $t->dropColumn(['verify_attempts', 'reorg_evidence']));
    }
};
