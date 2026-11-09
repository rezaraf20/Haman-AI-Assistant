<?php
namespace App\Filament\Forms;

use App\Support\Usdt;
use Filament\Forms\Components\TextInput;

/**
 * A USDT price as the admin types it ("19.99"), stored as integer
 * micro-USDT. Empty means "not sold in USDT" — not zero, and not a
 * conversion of the Toman price: the two prices are set independently.
 */
final class UsdtPriceInput
{
    public static function make(string $column): TextInput
    {
        return TextInput::make($column)
            ->label(__('usdt.price_field'))
            ->suffix('USDT')
            ->helperText(__('usdt.price_field_help'))
            ->formatStateUsing(fn ($state) => $state === null ? null : Usdt::plain((int) $state))
            ->dehydrateStateUsing(fn ($state) => ($state === null || trim((string) $state) === '') ? null : Usdt::toMicro((string) $state))
            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                if ($value !== null && trim((string) $value) !== '' && Usdt::toMicro((string) $value) === null) {
                    $fail(__('usdt.amount_invalid'));
                }
            });
    }
}
