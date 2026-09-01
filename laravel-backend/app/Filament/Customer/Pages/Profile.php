<?php
namespace App\Filament\Customer\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\{TextInput, Textarea};
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
    protected static ?string $navigationLabel = 'اطلاعات حساب';
    protected static ?string $title = 'اطلاعات حساب';
    protected static ?int $navigationSort = 99;

    public ?array $data = [];

    public function mount(): void {
        $this->form->fill(auth()->user()->only([
            'first_name', 'last_name', 'email', 'phone', 'national_id', 'address',
        ]));
    }

    public function form(Form $form): Form {
        return $form->schema([
            TextInput::make('first_name')->label('نام')->required()->maxLength(100),
            TextInput::make('last_name')->label('نام خانوادگی')->required()->maxLength(100),
            TextInput::make('email')->label('ایمیل')->email()->required()->maxLength(255)
                ->rule(fn () => Rule::unique('users', 'email')->ignore(auth()->id())),
            Textarea::make('address')->label('آدرس')->required()->rows(3),
            TextInput::make('phone')->label('شماره موبایل')->disabled()
                ->helperText('شماره موبایل قابل ویرایش نیست.'),
            TextInput::make('national_id')->label('کد ملی')->disabled()
                ->helperText('کد ملی قابل ویرایش نیست.'),
        ])->statePath('data');
    }

    public function save(): void {
        $state = $this->form->getState();
        auth()->user()->update([
            'first_name' => $state['first_name'],
            'last_name'  => $state['last_name'],
            'email'      => $state['email'],
            'address'    => $state['address'],
            'name'       => trim($state['first_name'] . ' ' . $state['last_name']),
        ]);
        Notification::make()->title('اطلاعات شما ذخیره شد')->success()->send();
    }
}
