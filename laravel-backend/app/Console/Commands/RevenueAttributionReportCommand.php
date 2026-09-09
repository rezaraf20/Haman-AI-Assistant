<?php
namespace App\Console\Commands;

use App\Models\Tenant\Chatbot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * doc-04's revenue-attribution acceptance criterion: for a real order that
 * came through this chatbot, show which bot-driven event (product_mentioned
 * or cart_link_generated) most plausibly led to it — same conversation_id
 * (see SyncService::recordOrder(), which only ever accepts one that
 * genuinely belongs to this chatbot's own conversations table) AND at
 * least one matching product_id between the mediating event and the
 * order's own line items.
 *
 * This is a report, not a guarantee — a conversation_id only exists when
 * the customer's browser still had the hamman_conv_id cookie at checkout
 * time (see hamman-widget.js's persistConv()), and a product match is
 * correlation within one conversation, not a certainty the mention caused
 * the purchase. Stated plainly in the report's own output rather than
 * oversold as more than it is.
 */
class RevenueAttributionReportCommand extends Command {
    protected $signature = 'hamman:revenue-attribution {chatbot_id} {--days=90}';
    protected $description = 'Show which recent orders for a chatbot can be attributed to a bot-driven product mention or cart link';

    public function handle(): void {
        $chatbotId = $this->argument('chatbot_id');
        $days = (int) $this->option('days');

        $index = DB::table('chatbot_index')->where('chatbot_id', $chatbotId)->first();
        if (!$index) {
            $this->error("No chatbot found with id {$chatbotId}");
            return;
        }

        DB::statement("SET search_path TO {$index->schema_name}, public");

        $orders = DB::table('orders')
            ->where('chatbot_id', $chatbotId)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->get();

        if ($orders->isEmpty()) {
            $this->info("No orders recorded for this chatbot in the last {$days} days.");
            DB::statement('SET search_path TO public');
            return;
        }

        $attributedCount = 0;
        $rows = [];

        foreach ($orders as $order) {
            $lineItems = json_decode($order->line_items ?? '[]', true) ?: [];
            $orderProductIds = array_column($lineItems, 'product_id');

            $attribution = null;
            if ($order->conversation_id) {
                $events = DB::table('conversation_events')
                    ->where('conversation_id', $order->conversation_id)
                    ->whereIn('event_type', ['cart_link_generated', 'product_mentioned'])
                    ->where('created_at', '<=', $order->created_at)
                    ->orderByDesc('created_at')
                    ->get(['event_type', 'payload', 'created_at']);

                foreach ($events as $e) {
                    $payload = json_decode($e->payload ?? '{}', true) ?: [];
                    $pid = $payload['product_id'] ?? null;
                    if ($pid !== null && in_array((int) $pid, $orderProductIds, true)) {
                        $attribution = [
                            'event_type' => $e->event_type,
                            'product_id' => $pid,
                            'event_at'   => $e->created_at,
                        ];
                        break; // most recent matching event before the order
                    }
                }
            }

            if ($attribution) $attributedCount++;

            $rows[] = [
                'woo_order_id'     => $order->woo_order_id,
                'total'            => $order->total . ' ' . $order->currency,
                'has_conversation' => $order->conversation_id ? 'yes' : 'no',
                'attributed'       => $attribution ? 'YES' : 'no',
                'via'              => $attribution ? "{$attribution['event_type']} (product {$attribution['product_id']})" : '-',
                'order_at'         => $order->created_at,
            ];
        }

        $this->table(
            ['Order ID', 'Total', 'Had conversation_id', 'Attributed to bot', 'Via event', 'Placed at'],
            array_map('array_values', $rows)
        );
        $this->newLine();
        $this->info("{$attributedCount} of {$orders->count()} orders in the last {$days} days are attributable to a product_mentioned/cart_link_generated event in the same conversation.");
        $this->comment('A conversation_id only exists when the customer\'s browser still had the widget\'s cookie at checkout — this undercounts real bot-assisted sales where it had already expired or cookies were blocked.');

        DB::statement('SET search_path TO public');
    }
}
