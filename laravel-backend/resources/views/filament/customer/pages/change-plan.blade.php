<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ __('plan.current_plan_heading') }}</x-slot>
            <p class="text-lg font-bold">{{ $this->getCurrentPlan()->display_name }}</p>
            @if ($pending = $this->getPendingPlan())
                <p class="text-sm text-gray-500 mt-2">
                    {{ __('plan.pending_downgrade_notice', ['name' => $pending->display_name, 'date' => auth()->user()->tenant->pending_plan_effective_at?->toDateString()]) }}
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('plan.available_plans_heading') }}</x-slot>
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
