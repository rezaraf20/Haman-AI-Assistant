@php
    $conversations = $this->getConversations();
    $transcript = $this->getTranscript();
@endphp
<x-filament-panels::page>
    <div class="space-y-6">

        @if (! $this->reason)
            {{-- Nothing is read before a reason is given. --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('staff_conversations.reason_required') }}</x-slot>
                <x-slot name="description">{{ __('staff_conversations.reason_help') }}</x-slot>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->reasonOptions() as $value => $label)
                        <x-filament::button wire:click="setReason('{{ $value }}')" color="gray">
                            {{ $label }}
                        </x-filament::button>
                    @endforeach
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div>
                        <span class="text-gray-500">{{ __('staff_conversations.tenant') }}:</span>
                        <strong>{{ $this->tenantName() ?? '—' }}</strong>
                    </div>
                    <div>
                        <span class="text-gray-500">{{ __('staff_conversations.reason') }}:</span>
                        <strong>{{ $this->reasonOptions()[$this->reason] ?? $this->reason }}</strong>
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">{{ __('staff_conversations.audit_note') }}</p>
            </x-filament::section>

            <div class="grid gap-6 md:grid-cols-3">
                <x-filament::section class="md:col-span-1">
                    <x-slot name="heading">{{ __('staff_conversations.recent') }}</x-slot>
                    @if (empty($conversations))
                        <p class="text-sm text-gray-500">{{ __('staff_conversations.none') }}</p>
                    @else
                        <ul class="text-sm divide-y">
                            @foreach ($conversations as $c)
                                <li class="py-2">
                                    <button type="button" wire:click="openConversation('{{ $c->id }}')"
                                            class="text-start w-full hover:underline {{ $this->conversationId === $c->id ? 'text-primary-600 font-medium' : '' }}">
                                        <span dir="ltr">{{ \App\Support\Jalali::dateTime($c->started_at) }}</span>
                                        <span class="text-gray-400">· {{ $c->message_count }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>

                <x-filament::section class="md:col-span-2">
                    <x-slot name="heading">{{ __('staff_conversations.transcript') }}</x-slot>
                    @if (! $this->conversationId)
                        <p class="text-sm text-gray-500">{{ __('staff_conversations.pick_one') }}</p>
                    @else
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <span class="text-xs text-gray-500">
                                {{ $this->isRevealed($this->conversationId)
                                    ? __('staff_conversations.revealed_note')
                                    : __('staff_conversations.masked_note') }}
                            </span>
                            @unless ($this->isRevealed($this->conversationId))
                                <x-filament::button size="xs" color="warning"
                                    wire:click="reveal('{{ $this->conversationId }}')"
                                    wire:confirm="{{ __('staff_conversations.reveal_confirm') }}">
                                    {{ __('staff_conversations.reveal') }}
                                </x-filament::button>
                            @endunless
                        </div>

                        <div class="space-y-3">
                            @foreach ($transcript as $m)
                                <div @class([
                                    'rounded-lg px-3 py-2 text-sm max-w-3xl',
                                    'bg-primary-50 text-gray-800 ms-auto' => $m->role === 'user',
                                    'bg-gray-100 text-gray-700' => $m->role !== 'user',
                                ])>
                                    <div class="text-[11px] text-gray-500 mb-1">
                                        {{ $m->role === 'user' ? __('suggestions.role_user') : __('suggestions.role_bot') }}
                                        @if (! empty($m->is_unanswered))
                                            <span class="text-danger-600">· {{ __('suggestions.flagged_unanswered') }}</span>
                                        @endif
                                    </div>
                                    <div class="whitespace-pre-wrap">{{ $m->content }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
