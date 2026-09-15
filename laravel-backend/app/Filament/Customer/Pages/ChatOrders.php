<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * create_payment_link (doc-04) — "a view of orders created from chat,
 * with payment status" was one of the task's own explicit requirements.
 * Filters the SAME orders table revenue attribution already built
 * (created_by_bot=true only — an order that merely got cookie-attributed
 * after a normal checkout, doc-04's earlier Intent-analytics task, is a
 * different thing and stays out of this view). Status here is kept live
 * by Haman_Sync_Manager::on_order_status_changed() on the plugin side,
 * not by this page polling anything.
 */
class ChatOrders extends Page {
    protected static string $view = 'filament.customer.pages.chat-orders';
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    public static function getNavigationLabel(): string { return __('chat_orders.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_chatbots'); }
    public function getTitle(): string { return __('chat_orders.nav'); }

    public function getOrders(): array {
        $tenant = auth()->user()->tenant;
        DB::statement("SET search_path TO {$tenant->schema_name}, public");
        $orders = DB::table('orders')
            ->where('created_by_bot', true)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
        DB::statement('SET search_path TO public');
        return $orders->all();
    }

    // Every WooCommerce core order status this feature could ever produce
    // (pending at creation, then whatever the merchant's payment gateway
    // or the auto-cancel cron sets it to) — a plain match() rather than a
    // lang-file-key guess, since __() returns the key itself (not an
    // empty/falsy value) for a status this doesn't recognize.
    public function statusLabel(string $status): string {
        return match ($status) {
            'pending'    => __('chat_orders.status_pending'),
            'processing' => __('chat_orders.status_processing'),
            'on-hold'    => __('chat_orders.status_on_hold'),
            'completed'  => __('chat_orders.status_completed'),
            'cancelled'  => __('chat_orders.status_cancelled'),
            'refunded'   => __('chat_orders.status_refunded'),
            'failed'     => __('chat_orders.status_failed'),
            default      => $status,
        };
    }
}
