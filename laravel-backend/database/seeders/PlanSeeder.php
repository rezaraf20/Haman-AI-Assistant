<?php
namespace Database\Seeders;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder {
    public function run(): void {
        // features holds display bullets only — see Plan::getDisplayFeaturesAttribute().
        // It used to smuggle a 'woocommerce' capability flag in here instead,
        // which nothing ever actually read (grepped: no code checks
        // $plan->features['woocommerce']) and which foreach-printed as a bare
        // "1" on every paid plan's public pricing card. Left empty here
        // rather than inventing marketing copy this codebase has no basis
        // for — see LandingController's own docblock on not claiming
        // anything the product can't back. An admin adds real bullets
        // through PlanResource's Repeater field once there's something
        // specific to say.
        // is_public follows the same intent 2026_09_17_000001_add_is_public_
        // to_plans_table's own one-time UPDATE encodes (the three paid tiers
        // are worth advertising, Free is the auto-assigned default and
        // isn't) — set here directly rather than left to that migration,
        // since on a genuinely fresh install migrations run (in order)
        // BEFORE this seeder ever creates these rows, so that migration's
        // UPDATE ... WHERE slug IN (...) would otherwise match zero rows
        // and every paid plan would silently stay unpublished forever.
        $plans = [
            ['id' => '00000000-0000-0000-0000-000000000001', 'name'=>'Free',       'slug'=>'free',       'price_monthly'=>0,   'max_chatbots'=>1, 'max_tokens_monthly'=>50000,   'max_documents'=>20,   'max_messages_monthly'=>200,   'features'=>[], 'sort_order'=>0, 'is_public'=>false],
            ['id' => '00000000-0000-0000-0000-000000000002', 'name'=>'Starter',    'slug'=>'starter',    'price_monthly'=>29,  'max_chatbots'=>2, 'max_tokens_monthly'=>500000,  'max_documents'=>200,  'max_messages_monthly'=>2000,  'features'=>[], 'sort_order'=>1, 'is_public'=>true],
            ['id' => '00000000-0000-0000-0000-000000000003', 'name'=>'Growth',     'slug'=>'growth',     'price_monthly'=>99,  'max_chatbots'=>5, 'max_tokens_monthly'=>2000000, 'max_documents'=>1000, 'max_messages_monthly'=>10000, 'features'=>[], 'sort_order'=>2, 'is_public'=>true],
            ['id' => '00000000-0000-0000-0000-000000000004', 'name'=>'Enterprise', 'slug'=>'enterprise', 'price_monthly'=>299, 'max_chatbots'=>20,'max_tokens_monthly'=>10000000,'max_documents'=>5000, 'max_messages_monthly'=>50000, 'features'=>[], 'sort_order'=>3, 'is_public'=>true],
        ];

        // Raw DB::table()->insertOrIgnore(), not Plan::firstOrCreate(): this
        // runs on every single deploy (docker-entrypoint.sh), and needs to
        // write these fixed ids ONLY the first time each row doesn't exist
        // yet — never touching an existing row again, which is what stops
        // an admin's real price edit (or a deliberate is_active=false) from
        // being reverted by the next deploy (the direct, confirmed cause of
        // wrong prices reaching the public pricing page — see the
        // 2026-09-30 pricing/currency audit).
        //
        // Plan::firstOrCreate(['id' => $p['id']], [...]) looks like it
        // should do exactly that, but doesn't: 'id' is deliberately absent
        // from Plan::$fillable (same reason as every other HasUuid model in
        // this app), so on the CREATE path Eloquent's mass-assignment
        // silently drops the given id and HasUuid mints a random one
        // instead — the row saves, but never under the id this seeder just
        // searched for. The next run's firstOrCreate then searches for that
        // same id again, finds nothing (the real row has a different one),
        // and tries to insert a second time — colliding on the unique slug
        // instead. This only ever went unnoticed in production because
        // these 4 rows were already seeded with their correct fixed ids by
        // an earlier, pre-Eloquent version of this seeder (raw SQL, no
        // fillable restriction) before updateOrCreate/firstOrCreate was
        // ever introduced here — so the CREATE path, and this exact bug,
        // was never actually exercised there. A genuinely fresh install (or
        // this seeder's own test) has no such head start and hits it
        // immediately. insertOrIgnore is a raw query builder call — no
        // Eloquent fillable filtering — and is a single atomic
        // "insert only if this id isn't already there" per row, matching
        // on the table's real primary key (id), not the seeder's own
        // application-level read-then-write.
        $now = now();
        foreach ($plans as $p) {
            DB::table('plans')->insertOrIgnore([
                'id'                   => $p['id'],
                'name'                 => $p['name'],
                'slug'                 => $p['slug'],
                'price_monthly'        => $p['price_monthly'],
                'price_yearly'         => $p['price_monthly'] * 10,
                'max_chatbots'         => $p['max_chatbots'],
                'max_tokens_monthly'   => $p['max_tokens_monthly'],
                'max_documents'        => $p['max_documents'],
                'max_messages_monthly' => $p['max_messages_monthly'],
                'features'             => json_encode($p['features']),
                'sort_order'           => $p['sort_order'],
                'is_active'            => true,
                'is_public'            => $p['is_public'],
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }
    }
}
