<?php
namespace App\Filament\Pages;

use App\Models\PlatformSetting;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Toggle, Section};
use Filament\Notifications\Notification;

// Platform-wide config Reza needs to be able to change himself — Zarinpal
// credentials today, a natural place for anything similar later — without
// asking for a code change + image rebuild every time. Backed by the single-
// row platform_settings table (see PlatformSetting::current()), not .env.
class Settings extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.pages.settings';
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string { return __('panel.settings_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_infrastructure'); }
    public function getTitle(): string { return __('panel.settings_title'); }

    public ?array $data = [];

    public function mount(): void {
        $this->form->fill(PlatformSetting::current()->toArray());
    }

    public function form(Form $form): Form {
        return $form->schema([
            Section::make(__('panel.zarinpal_section'))
                ->description(__('panel.zarinpal_section_desc'))
                ->schema([
                    TextInput::make('zarinpal_merchant_id')
                        ->label(__('panel.merchant_id'))
                        ->maxLength(255),
                    Toggle::make('zarinpal_sandbox')
                        ->label(__('panel.sandbox_mode'))
                        ->helperText(__('panel.sandbox_mode_help')),
                ]),
            Section::make(__('panel.melipayamak_section'))
                ->description(__('panel.melipayamak_section_desc'))
                ->schema([
                    TextInput::make('melipayamak_username')
                        ->label(__('common.username')),
                    TextInput::make('melipayamak_password')
                        ->label(__('common.password'))
                        ->password()->revealable(),
                    TextInput::make('melipayamak_sender')
                        ->label(__('panel.sender_number')),
                    Toggle::make('melipayamak_use_pattern')
                        ->label(__('panel.send_otp_pattern'))
                        ->live()
                        ->helperText(__('panel.send_otp_pattern_help')),
                    TextInput::make('melipayamak_pattern_id')
                        ->label(__('panel.pattern_id'))
                        ->numeric()
                        ->visible(fn ($get) => $get('melipayamak_use_pattern'))
                        ->helperText(__('panel.pattern_id_help')),
                ]),
        ])->statePath('data');
    }

    public function save(): void {
        $state = $this->form->getState();
        PlatformSetting::current()->update($state);
        Notification::make()->title(__('common.settings_saved'))->success()->send();
    }
}
