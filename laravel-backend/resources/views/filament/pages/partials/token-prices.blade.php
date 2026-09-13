{{-- Read-only mirror of each LLM profile's token pricing. Edited on the
     profile itself; shown here because "what does a million tokens cost"
     is a pricing question and sending someone elsewhere to answer it is how
     a number ends up changed in one place only. --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-gray-500">
            <tr>
                <th class="p-2 text-start font-medium">{{ __('settings.model_profile') }}</th>
                <th class="p-2 text-start font-medium">{{ __('settings.model_input_price') }}</th>
                <th class="p-2 text-start font-medium">{{ __('settings.model_output_price') }}</th>
                <th class="p-2 text-start font-medium">{{ __('common.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($profiles as $profile)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="p-2">
                        <div class="font-medium">{{ $profile->name }}</div>
                        <div class="text-xs text-gray-500">{{ $profile->provider }} · {{ $profile->model_name }}</div>
                    </td>
                    <td class="p-2">{{ number_format((float) $profile->input_price_per_1m_toman) }}</td>
                    <td class="p-2">{{ number_format((float) $profile->output_price_per_1m_toman) }}</td>
                    <td class="p-2">
                        <span class="{{ $profile->is_active ? 'text-success-600' : 'text-gray-400' }}">
                            {{ $profile->is_active ? __('common.active') : __('common.inactive') }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-3 text-gray-500">{{ __('settings.no_model_profiles') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="mt-2 text-xs text-gray-500">{{ __('settings.model_token_prices_edit_hint') }}</p>
</div>
