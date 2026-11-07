@php
    $leads = $this->getLeads();
    $statusTone = fn ($s) => match ($s) { 'new' => 'info', 'contacted' => 'warning', default => 'neutral' };
    $typeTone = fn ($t) => match ($t ?? 'unanswered') { 'out_of_stock' => 'warning', 'not_in_catalog' => 'danger', 'volunteered' => 'success', default => 'neutral' };
@endphp
<x-filament-panels::page>
    <div class="hp-stack">
        {{-- Two independent filters. The pressed state is aria-pressed, so it
             is announced, not only shown in colour. --}}
        <div class="hp-card hp-stack hp-stack--tight">
            <div class="hp-row" role="group" aria-label="{{ __('common.status') }}">
                <span class="hp-small hp-muted">{{ __('common.status') }}:</span>
                @foreach (['all' => __('leads.filter_all'), 'new' => __('leads.status_new'), 'contacted' => __('leads.status_contacted'), 'closed' => __('leads.status_closed')] as $value => $label)
                    <button type="button" class="hp-chip" wire:click="setStatusFilter('{{ $value }}')" aria-pressed="{{ $statusFilter === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <div class="hp-row" role="group" aria-label="{{ __('leads.filter_type') }}">
                <span class="hp-small hp-muted">{{ __('leads.filter_type') }}:</span>
                @foreach ($this->typeOptions() as $value => $label)
                    <button type="button" class="hp-chip" wire:click="setTypeFilter('{{ $value }}')" aria-pressed="{{ $typeFilter === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="hp-loadable" wire:loading.class="hp-is-loading" wire:target="setStatusFilter, setTypeFilter, markContacted, markClosed">
            @if (empty($leads))
                <div class="hp-card">
                    <div class="hp-empty">
                        <x-filament::icon icon="heroicon-o-user-plus" class="hp-empty__icon" />
                        <p class="hp-empty__title">{{ __('leads.empty') }}</p>
                        <p class="hp-empty__desc">{{ __('portal.leads_empty_desc') }}</p>
                    </div>
                </div>
            @else
                <div class="hp-table-wrap">
                    <table class="hp-table">
                        <thead>
                            <tr>
                                <th>{{ __('leads.col_contact') }}</th>
                                <th>{{ __('leads.col_type') }}</th>
                                <th>{{ __('leads.col_requested_item') }}</th>
                                <th>{{ __('leads.col_question') }}</th>
                                <th>{{ __('common.status') }}</th>
                                <th>{{ __('common.created_at') }}</th>
                                <th>{{ __('leads.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($leads as $lead)
                                <tr wire:key="lead-{{ $lead->id }}">
                                    <td>
                                        <span class="hp-ltr">{{ $lead->contact }}</span>
                                        <div class="hp-small hp-muted">{{ $lead->contact_type === 'email' ? __('common.email') : __('panel.mobile_number') }}</div>
                                    </td>
                                    <td><span class="hp-badge hp-badge--{{ $typeTone($lead->type) }}">{{ $this->typeLabel($lead->type ?? 'unanswered') }}</span></td>
                                    <td class="hp-strong">{{ $lead->requested_item ?: '—' }}</td>
                                    <td><div class="hp-truncate hp-cell-clip" title="{{ $lead->question }}">{{ $lead->question }}</div></td>
                                    <td>
                                        <span class="hp-badge hp-badge--{{ $statusTone($lead->status) }}">
                                            {{ match ($lead->status) { 'new' => __('leads.status_new'), 'contacted' => __('leads.status_contacted'), default => __('leads.status_closed') } }}
                                        </span>
                                    </td>
                                    <td class="hp-nowrap hp-muted">{{ \App\Support\Jalali::dateTime($lead->created_at) }}</td>
                                    <td>
                                        <div class="hp-row">
                                            <a href="{{ $this->conversationUrl($lead->conversation_id) }}" class="hp-link hp-small">{{ __('leads.view_conversation') }}</a>
                                            @if ($lead->status === 'new')
                                                <button type="button" wire:click="markContacted('{{ $lead->id }}')" class="hp-text-btn">{{ __('leads.mark_contacted') }}</button>
                                            @endif
                                            @if ($lead->status !== 'closed')
                                                <button type="button" wire:click="markClosed('{{ $lead->id }}')" class="hp-text-btn hp-text-btn--muted">{{ __('leads.mark_closed') }}</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
