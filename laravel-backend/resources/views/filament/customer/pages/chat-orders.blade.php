@php
    $orders = $this->getOrders();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            @if (empty($orders))
                <p class="text-sm text-gray-500">{{ __('chat_orders.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-start text-gray-500 border-b">
                                <th class="py-2 pe-4 text-start">{{ __('chat_orders.col_order') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('chat_orders.col_total') }}</th>
                                <th class="py-2 pe-4 text-start">{{ __('common.status') }}</th>
                                <th class="py-2 text-start">{{ __('common.created_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $order)
                                <tr class="border-b last:border-0" wire:key="chat-order-{{ $order->id }}">
                                    <td class="py-2 pe-4">
                                        <span dir="ltr" class="inline-block">#{{ $order->woo_order_id }}</span>
                                    </td>
                                    <td class="py-2 pe-4">
                                        <span dir="ltr" class="inline-block">{{ number_format((float) $order->total) }} {{ $order->currency }}</span>
                                    </td>
                                    <td class="py-2 pe-4">
                                        @php
                                            $badgeColor = match ($order->status) {
                                                'completed', 'processing' => 'bg-success-100 text-success-700',
                                                'cancelled', 'failed'     => 'bg-danger-100 text-danger-700',
                                                default                   => 'bg-warning-100 text-warning-700',
                                            };
                                        @endphp
                                        <span class="px-2 py-0.5 rounded text-xs {{ $badgeColor }}">{{ $this->statusLabel($order->status) }}</span>
                                    </td>
                                    <td class="py-2 text-gray-500">{{ \App\Support\Jalali::dateTime($order->created_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
