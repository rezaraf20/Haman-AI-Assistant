<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * "Which files got indexed and which got skipped, and why" — requirement 6
 * of the PDF-datasheet feature (see pdf_service.py / embed.py for the
 * extraction side). Follows Leads.php's proven pattern: documents lives in
 * the tenant schema, not `public`, so this is a plain manual query with an
 * explicit search_path switch rather than a standard Filament ->query()
 * Resource bound to an Eloquent model.
 */
class Datasheets extends Page {
    protected static string $view = 'filament.customer.pages.datasheets';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string { return __('datasheets.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('datasheets.title'); }

    public function getAttachments(): array {
        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $docs = DB::table('documents')
            ->where('source_type', 'product_attachment')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
        DB::statement('SET search_path TO public');

        return $docs->map(function ($d) {
            $meta = json_decode($d->metadata ?? '{}', true) ?: [];
            return [
                'id'            => $d->id,
                'title'         => $d->title,
                'product_name'  => $meta['product_name'] ?? null,
                'status'        => $d->status,
                'error_message' => $d->error_message,
                'page_count'    => $meta['page_count'] ?? null,
                'cost_toman'    => $meta['embedding_cost_toman'] ?? null,
                'created_at'    => $d->created_at,
            ];
        })->all();
    }
}
