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
    protected static ?string $navigationLabel = 'تنظیمات';
    protected static ?string $title = 'تنظیمات پلتفرم';
    protected static ?int $navigationSort = 9;

    public ?array $data = [];

    public function mount(): void {
        $this->form->fill(PlatformSetting::current()->toArray());
    }

    public function form(Form $form): Form {
        return $form->schema([
            Section::make('زرین‌پال')
                ->description('درگاه پرداخت شارژ کیف پول. این اطلاعات رو از پنل زرین‌پال (zarinpal.com) بردار.')
                ->schema([
                    TextInput::make('zarinpal_merchant_id')
                        ->label('کد پذیرنده (Merchant ID)')
                        ->maxLength(255),
                    Toggle::make('zarinpal_sandbox')
                        ->label('حالت آزمایشی (Sandbox)')
                        ->helperText('روشن = فقط پرداخت تستی، پول واقعی جابه‌جا نمی‌شه. وقتی کد پذیرنده‌ی واقعی گرفتی و آماده‌ی پرداخت واقعی بودی، خاموشش کن.'),
                ]),
            Section::make('ملی‌پیامک (پیامک)')
                ->description('برای ارسال کد تائید ورود/ثبت‌نام مشتریان استفاده می‌شه — از API آدرس rest.payamak-panel.com (نام‌کاربری+رمزعبور)، نه سیستم کلید کنسول.')
                ->schema([
                    TextInput::make('melipayamak_username')
                        ->label('نام کاربری'),
                    TextInput::make('melipayamak_password')
                        ->label('رمز عبور')
                        ->password()->revealable(),
                    TextInput::make('melipayamak_sender')
                        ->label('شماره ارسال‌کننده'),
                    Toggle::make('melipayamak_use_pattern')
                        ->label('ارسال کد تائید با پترن')
                        ->live()
                        ->helperText('بسیاری از اپراتورهای ایرانی پیامک ساده‌ی کد تائید رو تبلیغاتی تشخیص می‌دن و فیلتر می‌کنن — یک پترن از پیش‌تائیدشده از پنل ملی‌پیامک این مشکل رو حل می‌کنه. وقتی پترن گرفتی، این گزینه رو روشن کن.'),
                    TextInput::make('melipayamak_pattern_id')
                        ->label('شناسه پترن (Body ID)')
                        ->numeric()
                        ->visible(fn ($get) => $get('melipayamak_use_pattern'))
                        ->helperText('شناسه‌ی عددی پترن ثبت‌شده در پنل ملی‌پیامک — قالبش باید دقیقاً یک متغیر داشته باشه که با کد تائید پر می‌شه.'),
                ]),
        ])->statePath('data');
    }

    public function save(): void {
        $state = $this->form->getState();
        PlatformSetting::current()->update($state);
        Notification::make()->title('تنظیمات ذخیره شد')->success()->send();
    }
}
