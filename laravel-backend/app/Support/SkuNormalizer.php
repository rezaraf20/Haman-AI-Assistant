<?php
namespace App\Support;

/**
 * Normalizes a product SKU/part number for exact-match lookup — a customer
 * typing "lm358n", "LM358-N", or "LM358 N" all means the same part as the
 * catalog's "LM358N". SyncService::syncProducts() writes this at sync time
 * into products.sku_normalized (indexed); rag_service.py's SKU-lookup path
 * applies the identical rule (uppercase, strip whitespace/hyphens) to
 * whatever alphanumeric token it extracts from the incoming question before
 * comparing — the two sides must stay in lockstep or a real typed variant
 * silently stops matching.
 */
class SkuNormalizer {
    public static function normalize(?string $value): ?string {
        $value = trim((string) $value);
        if ($value === '') return null;

        $normalized = strtoupper(preg_replace('/[\s\-]+/', '', $value));
        return $normalized !== '' ? $normalized : null;
    }
}
