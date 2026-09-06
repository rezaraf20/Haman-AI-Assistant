<?php
namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Select};
use Filament\Notifications\Notification;
use Illuminate\Validation\Rule;

// The admin panel had no account/profile page at all — no way for the
// platform owner to change their own language, or anything else about
// their own account, from inside /admin. Deliberately minimal compared to
// the customer portal's Profile page: no phone/national_id/address fields,
// since those are customer-signup concepts that don't apply here.
class Profile extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.pages.profile';
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';
    protected static ?int $navigationSort = 99;

    public static function getNavigationLabel(): string { return __('panel.account_info_nav'); }
    public function getTitle(): string { return __('panel.account_info_nav'); }

    public ?array $data = [];

    public function mount(): void {
        $this->form->fill(auth()->user()->only(['name', 'email', 'locale']));
    }

    public function form(Form $form): Form {
        return $form->schema([
            Select::make('locale')->label(__('panel.language'))->options([
                'fa' => __('panel.language_fa'),
                'en' => __('panel.language_en'),
            ])->required(),
            TextInput::make('name')->label(__('common.name'))->required()->maxLength(255),
            TextInput::make('email')->label(__('common.email'))->email()->required()->maxLength(255)
                ->rule(fn () => Rule::unique('users', 'email')->ignore(auth()->id())),
        ])->statePath('data');
    }

    public function save(): void {
        $state = $this->form->getState();
        $localeChanged = $state['locale'] !== auth()->user()->locale;

        auth()->user()->update([
            'name'   => $state['name'],
            'email'  => $state['email'],
            'locale' => $state['locale'],
        ]);
        Notification::make()->title(__('panel.profile_saved'))->success()->send();

        // Same reasoning as the customer portal's Profile page — RTL/LTR
        // direction and Filament's own bundled translations are resolved
        // once per full request by SetLocale, not per-Livewire-component.
        if ($localeChanged) {
            $this->redirect(request()->fullUrl(), navigate: false);
        }
    }
}
