<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Log, Schema};

/**
 * One chatbot = one domain.
 *
 * 1. domain_rejections — widget requests refused because they came from a
 *    site that is not the chatbot's registered domain (see
 *    App\Support\DomainRejections).
 *
 * 2. Every ACTIVE chatbot without a registered domain becomes inactive with
 *    disabled_reason = 'awaiting_domain' ("awaiting connection") — it used
 *    to answer any site at all. Its owner registers the domain in the
 *    portal (My chatbots → Register domain) and it switches back on.
 *    Chatbots already inactive for another reason (expired, suspended,
 *    unverified) are left as they are; TenantService::setChatbotActive()
 *    parks them as awaiting_domain whenever something would reactivate
 *    them without a domain.
 *
 *    The rows this touches are exactly what this read-only query lists —
 *    run it on production BEFORE deploying, so it is known which customers
 *    are affected:
 *
 *      SELECT ci.chatbot_id, ci.name, t.name AS tenant, t.email, ci.created_at
 *      FROM chatbot_index ci JOIN tenants t ON t.id = ci.tenant_id
 *      WHERE ci.is_active AND coalesce(trim(ci.primary_domain), '') = '';
 *
 *    The affected ids are also written to the application log.
 *
 * down() drops the table and re-activates exactly the chatbots this
 * migration parked (awaiting_domain and still without a domain).
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('domain_rejections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('chatbot_id')->index();
            $table->string('origin_domain', 255);
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['chatbot_id', 'origin_domain']);
        });

        $parked = DB::table('chatbot_index')
            ->where('is_active', true)
            ->whereRaw("coalesce(trim(primary_domain), '') = ''")
            ->get(['chatbot_id', 'schema_name']);

        foreach ($parked as $row) {
            DB::table('chatbot_index')->where('chatbot_id', $row->chatbot_id)
                ->update(['is_active' => false, 'disabled_reason' => 'awaiting_domain']);
            if ($row->schema_name && Schema::hasTable("{$row->schema_name}.chatbots")) {
                DB::table("{$row->schema_name}.chatbots")->where('id', $row->chatbot_id)->update(['is_active' => false]);
            }
        }

        Log::warning('one-chatbot-one-domain: chatbots parked as awaiting_domain', [
            'count' => $parked->count(),
            'chatbot_ids' => $parked->pluck('chatbot_id')->all(),
        ]);
    }

    public function down(): void {
        Schema::dropIfExists('domain_rejections');

        $rows = DB::table('chatbot_index')
            ->where('disabled_reason', 'awaiting_domain')
            ->whereRaw("coalesce(trim(primary_domain), '') = ''")
            ->get(['chatbot_id', 'schema_name']);
        foreach ($rows as $row) {
            DB::table('chatbot_index')->where('chatbot_id', $row->chatbot_id)
                ->update(['is_active' => true, 'disabled_reason' => null]);
            if ($row->schema_name && Schema::hasTable("{$row->schema_name}.chatbots")) {
                DB::table("{$row->schema_name}.chatbots")->where('id', $row->chatbot_id)->update(['is_active' => true]);
            }
        }
    }
};
