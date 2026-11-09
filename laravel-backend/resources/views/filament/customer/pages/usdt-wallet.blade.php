@php
    $deposit = $this->getDeposit();
    $detected = $deposit ? $this->getDetected() : null;
    $recent = $deposit ? null : $this->getRecentlyPaid();
@endphp
<x-filament-panels::page>
    <div class="hp-stack">
        <div class="hp-grid hp-grid--wide">
            <div class="hp-card hp-stat">
                <span class="hp-stat__label">{{ __('usdt.balance') }}</span>
                <span class="hp-stat__value hp-ltr">{{ $this->getBalance() }}</span>
                <span class="hp-stat__foot">{{ __('usdt.balance_note') }}</span>
            </div>
        </div>

        @if (!$this->isEnabled())
            <div class="hp-notice hp-notice--info" role="note">
                <x-filament::icon icon="heroicon-o-information-circle" class="hp-icon hp-notice__icon" />
                <div>{{ __('usdt.not_available') }}</div>
            </div>
        @elseif ($deposit)
            {{-- Polls while a request is open, so "detected" and "credited" appear without a reload. --}}
            <x-filament::section :heading="__('usdt.pay_heading')" wire:poll.10s>
                <div class="hp-stack">
                    <div class="hp-notice hp-notice--warning" role="note">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="hp-icon hp-notice__icon" />
                        <div><strong class="hp-strong">{{ __('usdt.network_label') }}</strong> {{ __('usdt.network_warning') }}</div>
                    </div>

                    <div class="hp-row" style="align-items: flex-start; gap: 1.5rem;">
                        <div class="hp-card" wire:ignore style="padding: 0.75rem;"
                             x-data="{ address: @js($deposit->address) }"
                             x-init="const draw = () => { if (!window.qrcode) return setTimeout(draw, 100); const q = qrcode(0, 'M'); q.addData(address); q.make(); $refs.qr.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true }); }; draw()">
                            <div x-ref="qr" style="width: 12rem; height: 12rem;" role="img" aria-label="{{ __('usdt.qr_label') }}"></div>
                        </div>

                        <div class="hp-stack hp-grow">
                            <div>
                                <div class="hp-muted hp-small">{{ __('usdt.exact_amount') }}</div>
                                <div class="hp-row" x-data="{ copied: false }">
                                    <span class="hp-stat__value hp-mono hp-ltr">{{ \App\Support\Usdt::plain($deposit->amount_micro) }}</span>
                                    <span class="hp-muted hp-ltr">USDT</span>
                                    <x-filament::button size="xs" color="gray" x-on:click="navigator.clipboard.writeText(@js(\App\Support\Usdt::plain($deposit->amount_micro))); copied = true; setTimeout(() => copied = false, 1500)">
                                        <span x-show="!copied">{{ __('usdt.copy') }}</span><span x-show="copied" x-cloak>{{ __('usdt.copied') }}</span>
                                    </x-filament::button>
                                </div>
                                <p class="hp-muted hp-small">{{ __('usdt.exact_amount_help') }}</p>
                            </div>

                            <div>
                                <div class="hp-muted hp-small">{{ __('usdt.address') }}</div>
                                <div class="hp-row" x-data="{ copied: false }">
                                    <code class="hp-mono hp-ltr" style="word-break: break-all;">{{ $deposit->address }}</code>
                                    <x-filament::button size="xs" color="gray" x-on:click="navigator.clipboard.writeText(@js($deposit->address)); copied = true; setTimeout(() => copied = false, 1500)">
                                        <span x-show="!copied">{{ __('usdt.copy') }}</span><span x-show="copied" x-cloak>{{ __('usdt.copied') }}</span>
                                    </x-filament::button>
                                </div>
                            </div>

                            <div class="hp-small" wire:ignore
                                 x-data="{ left: {{ (int) max(0, now()->diffInSeconds($deposit->expires_at, false)) }} }"
                                 x-init="setInterval(() => { if (left > 0) left-- }, 1000)">
                                {{ __('usdt.expires_in') }}
                                <span class="hp-mono hp-ltr" x-text="Math.floor(left / 60) + ':' + String(Math.floor(left % 60)).padStart(2, '0')"></span>
                            </div>

                            @if ($detected)
                                <div class="hp-notice hp-notice--info" role="status">
                                    <x-filament::icon icon="heroicon-o-arrow-path" class="hp-icon hp-notice__icon" />
                                    <div>
                                        <div class="hp-notice__title">{{ __('usdt.status_detected') }}</div>
                                        <div>{{ __('usdt.status_detected_help') }}</div>
                                        <a href="{{ \App\Support\Usdt::txUrl($detected->tx_hash) }}" target="_blank" rel="noopener" class="hp-link hp-mono hp-ltr">{{ substr($detected->tx_hash, 0, 18) }}…</a>
                                    </div>
                                </div>
                            @else
                                <div class="hp-muted hp-small">
                                    <strong class="hp-strong">{{ __('usdt.status_waiting') }}</strong> — {{ __('usdt.status_waiting_help') }}
                                </div>
                            @endif

                            <div>
                                <x-filament::button color="gray" size="sm" wire:click="cancelDeposit" wire:confirm="{{ __('usdt.cancel_confirm') }}">{{ __('usdt.cancel') }}</x-filament::button>
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @else
            @if ($recent && $recent->transfer)
                <div class="hp-notice hp-notice--info" role="status">
                    <x-filament::icon icon="heroicon-o-check-circle" class="hp-icon hp-notice__icon" />
                    <div>
                        <div class="hp-notice__title">{{ __('usdt.status_credited', ['amount' => \App\Support\Usdt::format($recent->transfer->amountMicro())]) }}</div>
                        <div>{{ __('usdt.tx_details', ['block' => number_format($recent->transfer->block_number)]) }}</div>
                        <a href="{{ \App\Support\Usdt::txUrl($recent->transfer->tx_hash) }}" target="_blank" rel="noopener" class="hp-link hp-mono hp-ltr" style="word-break: break-all;">{{ $recent->transfer->tx_hash }}</a>
                    </div>
                </div>
            @endif
            <x-filament::section :heading="__('usdt.topup_heading')" :description="__('usdt.topup_desc')">
                <form wire:submit="createDeposit" class="hp-stack">
                    {{ $this->form }}
                    <div><x-filament::button type="submit">{{ __('usdt.topup_action') }}</x-filament::button></div>
                </form>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('usdt.ledger_heading')">
            {{ $this->table }}
        </x-filament::section>
    </div>
    <script src="{{ asset('js/vendor/qrcode-generator/qrcode.js') }}?v=1.4.4"></script>
</x-filament-panels::page>
