<?php
use App\Support\SyncSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * chatbots.sync_settings, version 1 → version 2: the two fixed flags
 * (sync_pages, sync_posts) become a post_types list, so a site can sync any
 * of its custom post types ("portfolio", "service", …) — see
 * App\Support\SyncSettings for the full shape.
 *
 * Every existing row is rewritten through SyncSettings::normalize(), the
 * same function that reads a version-1 row at runtime, so a tenant's choice
 * means exactly what it meant before: pages on stays pages on, posts off
 * stays posts off, exclusions and the products toggle carry over untouched.
 * The column default changes too, for chatbots created on an existing
 * schema after this runs.
 *
 * Runs per tenant schema, same pattern as 2026_10_06_000001. A schema
 * without the chatbots table or the column (an incomplete tenant — see
 * FailedSyncsTable for that incident) is skipped, not failed: there is no
 * setting in it to lose.
 */
return new class extends Migration {
    public function up(): void
    {
        $default = json_encode(SyncSettings::DEFAULTS);

        foreach ($this->schemasWithColumn() as $schema) {
            DB::statement("ALTER TABLE {$schema}.chatbots ALTER COLUMN sync_settings SET DEFAULT '{$default}'::jsonb");

            foreach (DB::table("{$schema}.chatbots")->get(['id', 'sync_settings']) as $row) {
                $stored = json_decode((string) $row->sync_settings, true) ?: [];
                DB::table("{$schema}.chatbots")->where('id', $row->id)->update([
                    'sync_settings' => json_encode(SyncSettings::normalize($stored)),
                ]);
            }
        }
    }

    /**
     * Back to the two flags. Lossy by necessity: version 1 has nowhere to
     * keep a custom post type or a custom-field selection, so those are
     * dropped; pages/posts/products/exclusions round-trip exactly.
     */
    public function down(): void
    {
        $v1Default = '{"sync_products":true,"sync_pages":true,"sync_posts":false,"excluded_category_ids":[],"excluded_page_ids":[]}';

        foreach ($this->schemasWithColumn() as $schema) {
            DB::statement("ALTER TABLE {$schema}.chatbots ALTER COLUMN sync_settings SET DEFAULT '{$v1Default}'::jsonb");

            foreach (DB::table("{$schema}.chatbots")->get(['id', 'sync_settings']) as $row) {
                $v2 = SyncSettings::normalize(json_decode((string) $row->sync_settings, true) ?: []);
                DB::table("{$schema}.chatbots")->where('id', $row->id)->update([
                    'sync_settings' => json_encode([
                        'sync_products'         => $v2['sync_products'],
                        'sync_pages'            => in_array('page', $v2['post_types'], true),
                        'sync_posts'            => in_array('post', $v2['post_types'], true),
                        'excluded_category_ids' => $v2['excluded_category_ids'],
                        'excluded_page_ids'     => $v2['excluded_page_ids'],
                    ]),
                ]);
            }
        }
    }

    /** @return list<string> */
    private function schemasWithColumn(): array
    {
        $schemas = DB::table('tenants')->whereNull('deleted_at')->pluck('schema_name')->all();
        if (!$schemas) return [];

        return DB::table('information_schema.columns')
            ->whereIn('table_schema', $schemas)
            ->where('table_name', 'chatbots')
            ->where('column_name', 'sync_settings')
            ->pluck('table_schema')
            ->all();
    }
};
