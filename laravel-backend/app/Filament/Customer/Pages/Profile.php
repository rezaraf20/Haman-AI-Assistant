<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Textarea, Select};
use Filament\Notifications\Notification;
use Illuminate\Validation\Rule;

// Lets a customer see the profile info they gave at signup (name, email,
// phone, national ID, address) and correct the parts that are plausibly
// wrong (name/email/address) without giving them a way to touch the two
// fields that were verified at signup time — phone (via SMS OTP) and
// national ID — which stay disabled()+non-dehydrated here.
class Profile extends Page implements HasForms {
    use InteractsWithForms;

    protected static string $view = 'filament.customer.pages.profile';
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';
    protected static ?int $navigationSort = 99;

    public static function getNavigationLabel(): string { return __('panel.account_info_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_customer_account'); }
    public function getTitle(): string { return __('panel.account_info_nav'); }

    public ?array $data = [];

    public function mount(): void {
        $this->form->fill(auth()->user()->only([
            'first_name', 'last_name', 'email', 'phone', 'national_id', 'address', 'locale',
        ]));
    }

    public function form(Form $form): Form {
        return $form->schema([
            Select::make('locale')->label(__('panel.language'))->options([
                'fa' => __('panel.language_fa'),
                'en' => __('panel.language_en'),
            ])->required(),
            TextInput::make('first_name')->label(__('panel.first_name'))->required()->maxLength(100),
            TextInput::make('last_name')->label(__('panel.last_name'))->required()->maxLength(100),
            TextInput::make('email')->label(__('common.email'))->email()->required()->maxLength(255)
                ->rule(fn () => Rule::unique('users', 'email')->ignore(auth()->id())),
            Textarea::make('address')->label(__('common.address'))->required()->rows(3),
            TextInput::make('phone')->label(__('panel.mobile_number'))
                ->disabled(fn () => filled(auth()->user()->phone))
                ->helperText(fn () => filled(auth()->user()->phone) ? __('panel.phone_not_editable') : null),
            // Collected once at phone-flow signup and then locked (see
            // helperText) — but an email-flow signup (see EmailLogin) never
            // collects it at all, so it must stay editable for that account
            // until they set it themselves, or it could never be set.
            TextInput::make('national_id')->label(__('panel.national_id'))
                ->disabled(fn () => filled(auth()->user()->national_id))
                ->helperText(fn () => filled(auth()->user()->national_id) ? __('panel.national_id_not_editable') : null),
        ])->statePath('data');
    }

    public function save(): void {
        $state = $this->form->getState();
        $localeChanged = $state['locale'] !== auth()->user()->locale;

        auth()->user()->update([
            'first_name' => $state['first_name'],
            'last_name'  => $state['last_name'],
            'email'      => $state['email'],
            'address'    => $state['address'],
            'name'       => trim($state['first_name'] . ' ' . $state['last_name']),
            'locale'     => $state['locale'],
        ]);
        Notification::make()->title(__('panel.profile_saved'))->success()->send();

        // A saved locale change needs a full page reload, not just this
        // Livewire component re-rendering — RTL/LTR direction, Filament's own
        // bundled translations, and every other page's chrome are all
        // resolved once per full request by SetLocale, not per-component.
        //
        // static::getUrl(), not request()->fullUrl(): a Livewire action runs
        // inside the POST to /livewire/update, so request() there IS that
        // AJAX request, not the page the component is mounted on.
        // redirect(request()->fullUrl()) sent the browser to
        // /livewire/update itself — POST-only — and a plain top-level GET
        // navigation there is a 405. getUrl() asks Filament for this page's
        // own route instead of reading the transport request.
        if ($localeChanged) {
            $this->redirect(static::getUrl(), navigate: false);
        }
    }
}
