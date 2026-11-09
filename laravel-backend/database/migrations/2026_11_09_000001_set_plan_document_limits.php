<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Document limits per plan, decided 2026-11-09 (they had never been set on
 * purpose: Pro and Business still carried the seed's placeholders, 2,000
 * and 10,000):
 *
 *   Free       100   (unchanged; now shown on the pricing page)
 *   Pro      3,000
 *   Business 20,000
 *
 * The limit counts every live document of a chatbot (products, their PDFs,
 * pages, posts, custom post types, FAQs — SyncService::loadDocumentBudget()).
 * Editable afterwards in Admin → Plans; this runs once and never overwrites
 * a later change. Once embedding prices are entered, the embedding cost of
 * a full plan at these limits is what to check them against.
 */
return new class extends Migration {
    private const LIMITS = ['free' => 100, 'pro' => 3000, 'business' => 20000];

    public function up(): void {
        foreach (self::LIMITS as $slug => $limit) {
            DB::table('plans')->where('slug', $slug)->update(['max_documents' => $limit, 'updated_at' => now()]);
        }
    }

    public function down(): void {
        DB::table('plans')->where('slug', 'pro')->update(['max_documents' => 2000]);
        DB::table('plans')->where('slug', 'business')->update(['max_documents' => 10000]);
    }
};
