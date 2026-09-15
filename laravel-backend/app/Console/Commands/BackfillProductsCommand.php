<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\SkuNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild the products table from the product documents already synced.
 *
 * The part-number fast path (rag_service._lookup_by_sku) reads products, and
 * for every existing tenant that table is empty while hundreds of product
 * documents sit beside it. Part-number search is one of the three questions a
 * real customer asks most, and it has been answering nothing.
 *
 * The cause was a column type: products.tags was a Postgres TEXT[] while
 * Eloquent sends a JSON string, so every product write failed with "malformed
 * array literal" -- after the document for that product had already been
 * written, which is why the two tables disagree. The column is JSONB now, so
 * new syncs land correctly; nothing ever went back for the rows lost in
 * between, and nothing will until each shop happens to sync again.
 *
 * Everything products needs is in the documents: external_id is the
 * WooCommerce id, the metadata carries price, currency, stock status,
 * permalink and image, and the indexed text carries the SKU. So this rebuilds
 * from what is already here rather than waiting on a shop to be reachable.
 *
 * Re-runnable: it always re-derives from the documents and never invents a
 * value. A field the document does not carry is left null, currency included
 * -- naming the wrong currency is what showed an Iranian shop's prices in USD.
 */
class BackfillProductsCommand extends Command
{
    protected $signature = 'haman:backfill-products
        {--tenant= : Only this tenant id or schema name}
        {--dry-run : Report what would be written, write nothing}';

    protected $description = 'Rebuild each tenant\'s products table from its synced product documents';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('tenant');

        DB::statement('SET search_path TO public');
        $tenants = Tenant::query()
            ->when($only, fn ($q) => $q->where('id', $only)->orWhere('schema_name', $only))
            ->get(['id', 'name', 'schema_name']);

        if ($tenants->isEmpty()) {
            $this->error($only ? "No tenant matches {$only}." : 'No tenants.');
            return self::FAILURE;
        }

        $totals = ['written' => 0, 'skipped' => 0, 'tenants' => 0];

        foreach ($tenants as $tenant) {
            $result = $this->backfillTenant($tenant, $dryRun);
            if ($result === null) {
                continue;
            }

            $totals['written'] += $result['written'];
            $totals['skipped'] += $result['skipped'];
            $totals['tenants']++;

            $this->line(sprintf(
                '%-28s %4d product document(s) -> %4d row(s)%s',
                mb_strimwidth($tenant->name, 0, 28, ''),
                $result['written'] + $result['skipped'],
                $result['written'],
                $result['skipped'] ? " ({$result['skipped']} unusable)" : '',
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d product row(s) across %d tenant(s)%s.',
            $dryRun ? 'Would write' : 'Wrote',
            $totals['written'],
            $totals['tenants'],
            $totals['skipped'] ? ", skipped {$totals['skipped']}" : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{written:int, skipped:int}|null null when the schema has no
     *         usable product documents, or cannot be read at all.
     */
    private function backfillTenant(Tenant $tenant, bool $dryRun): ?array
    {
        try {
            DB::statement("SET search_path TO {$tenant->schema_name}, public");

            $documents = DB::table('documents')
                ->where('source_type', 'woocommerce_product')
                ->get(['chatbot_id', 'external_id', 'title', 'raw_content', 'metadata']);
        } catch (\Throwable $e) {
            // An incomplete tenant schema must not stop the rest.
            $this->warn("{$tenant->schema_name}: {$e->getMessage()}");
            $this->resetSearchPath();
            return null;
        }

        if ($documents->isEmpty()) {
            $this->resetSearchPath();
            return null;
        }

        $written = 0;
        $skipped = 0;

        foreach ($documents as $document) {
            $row = $this->productRow($document);
            if ($row === null) {
                $skipped++;
                continue;
            }

            if (!$dryRun) {
                try {
                    DB::table('products')->updateOrInsert(
                        ['chatbot_id' => $document->chatbot_id, 'woo_product_id' => $row['woo_product_id']],
                        $row,
                    );
                } catch (\Throwable $e) {
                    $this->warn("{$tenant->schema_name} product {$row['woo_product_id']}: {$e->getMessage()}");
                    $skipped++;
                    continue;
                }
            }
            $written++;
        }

        $this->resetSearchPath();

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * @return array<string, mixed>|null null when the document carries no
     *         usable WooCommerce id, which is the one field with no fallback.
     */
    private function productRow(object $document): ?array
    {
        $wooId = filter_var($document->external_id, FILTER_VALIDATE_INT);
        if ($wooId === false) {
            return null;
        }

        $meta = json_decode($document->metadata ?? '{}', true) ?: [];
        $sku = $this->field($document->raw_content, 'SKU');

        return [
            'woo_product_id' => $wooId,
            'name'           => $document->title ?: $this->field($document->raw_content, 'Product') ?: '(untitled)',
            'sku'            => $sku,
            'sku_normalized' => SkuNormalizer::normalize($sku),
            'price'          => is_numeric($meta['price'] ?? null) ? $meta['price'] : null,
            // The shop's own currency or nothing at all -- see the class note.
            'currency'       => $meta['currency'] ?? null,
            'stock_status'   => $meta['stock_status'] ?? 'instock',
            'permalink'      => $meta['permalink'] ?? null,
            'featured_image' => $meta['image'] ?? null,
            'synced_at'      => now(),
        ];
    }

    /**
     * One "Label: value" line out of the indexed product text, as SyncService
     * wrote it. Returns null for a label that is absent or empty, so an
     * unknown SKU stays unknown.
     */
    private function field(?string $content, string $label): ?string
    {
        if (!$content || !preg_match('/^' . preg_quote($label, '/') . ':[ \t]*(.*)$/m', $content, $m)) {
            return null;
        }

        $value = trim($m[1]);

        return $value === '' ? null : $value;
    }

    private function resetSearchPath(): void
    {
        // Never inside a failed transaction's teardown: Postgres aborts the
        // whole transaction on a failed statement, and this would then fail
        // too and escape in place of the real error.
        try {
            DB::statement('SET search_path TO public');
        } catch (\Throwable $e) {
        }
    }
}
