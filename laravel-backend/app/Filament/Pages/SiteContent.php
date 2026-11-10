<?php
namespace App\Filament\Pages;

use App\Support\{Brand, LandingContent, PlatformAccess, PlatformActivity};
use Filament\Forms\Components\{FileUpload, Grid, Placeholder, Repeater, Section, Tabs, Textarea, TextInput};
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;

/**
 * Editable copy and pictures for the public site, one tab per section of
 * the landing page (LandingContent::SECTIONS) plus FAQ, legal pages and
 * contact info — see LandingContent for how a save here reaches the live
 * page (Laravel's own translator, patched at boot; nothing to clear).
 *
 * Every line of lang/landing.php is here. A field shows the text the page
 * is using right now; emptying it restores the lang-file default (an
 * override is never an empty string). Headings in LandingContent::
 * RICH_KEYS accept the [[...]] emphasis marker and nothing else — no HTML
 * from this page ever reaches the public one.
 *
 * Every text field is fa/en side by side rather than one field per locale
 * tab, so a translation gap between the two is visible immediately.
 */
class SiteContent extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.site-content';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 11;

    /** Which section tab each image slot sits in, and its suggested aspect ratio. */
    private const IMAGE_TABS = ['hero' => ['hero', '1:1'], 'demo' => ['demo', '4:3'], 'why' => ['why', '4:3']];

    public static function canAccess(): bool { return PlatformAccess::allows('site_content'); }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('site_content'); }

    public static function getNavigationLabel(): string { return __('content.nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_infrastructure'); }
    public function getTitle(): string { return __('content.title'); }

    public ?array $data = [];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill($this->currentValues());
    }

    private function currentValues(): array
    {
        $values = [];

        foreach (LandingContent::LANDING_KEYS as $key) {
            foreach (LandingContent::LOCALES as $locale) {
                $values[$this->landingFieldName($key, $locale)] = LandingContent::get($key, $locale);
            }
        }

        // Only a genuinely uploaded file is shown as the FileUpload's value;
        // the shipped default is shown beside it, never presented as if
        // someone had chosen it (same rule as Settings' brand fields).
        foreach (LandingContent::IMAGE_SLOTS as $slot) {
            $values["image_{$slot}"] = LandingContent::imagePath($slot);
        }

        foreach (LandingContent::LEGAL_PAGES as $slug) {
            foreach (['title', 'body'] as $field) {
                foreach (LandingContent::LOCALES as $locale) {
                    $values[$this->legalFieldName($slug, $field, $locale)] =
                        $field === 'title'
                            ? LandingContent::legalTitle($slug, $locale)
                            : LandingContent::legalBody($slug, $locale);
                }
            }
        }

        $values['faq'] = LandingContent::faq();
        $values['contact'] = LandingContent::contact();

        return $values;
    }

    /** Dots are Filament's nesting separator, so they cannot survive in a field name. */
    private function landingFieldName(string $key, string $locale): string
    {
        return "landing__{$key}__{$locale}";
    }

    private function legalFieldName(string $slug, string $field, string $locale): string
    {
        return "legal__{$slug}__{$field}__{$locale}";
    }

    public function form(Form $form): Form
    {
        $tabs = [];
        foreach (LandingContent::SECTIONS as $section => $keys) {
            $schema = [];
            foreach (self::IMAGE_TABS as $slot => [$tab, $ratio]) {
                if ($tab === $section) $schema[] = $this->imageField($slot, $ratio);
            }
            foreach ($keys as $key) {
                $schema[] = $this->bilingualField($key);
            }
            if ($section === 'faq') {
                $schema[] = $this->faqRepeater();
            }
            $tabs[] = Tabs\Tab::make(__("content.tab_{$section}"))->schema($schema);
        }

        $tabs[] = Tabs\Tab::make(__('content.tab_legal'))->schema(
            collect(LandingContent::LEGAL_PAGES)->map(fn ($slug) =>
                Section::make(__("content.legal_{$slug}"))->schema([
                    $this->legalBilingualField($slug, 'title', __('content.field_legal_title')),
                    $this->legalBilingualField($slug, 'body', __('content.field_legal_body'), textarea: true),
                ])->collapsible()
            )->all()
        );

        $tabs[] = Tabs\Tab::make(__('content.tab_contact'))->schema([
            Section::make(__('content.tab_contact'))
                ->description(__('content.contact_desc'))
                ->schema([
                    TextInput::make('contact.address')->label(__('content.field_contact_address')),
                    TextInput::make('contact.phone')->label(__('content.field_contact_phone')),
                    TextInput::make('contact.email')->label(__('content.field_contact_email'))->email(),
                ]),
        ]);

        return $form->schema([
            Tabs::make('content')->tabs($tabs)->persistTabInQueryString(),
        ])->statePath('data');
    }

    private function faqRepeater(): Repeater
    {
        return Repeater::make('faq')
            ->label('')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('q_fa')->label(__('content.faq_question') . ' (' . __('content.locale_fa') . ')')->required(),
                    TextInput::make('q_en')->label(__('content.faq_question') . ' (' . __('content.locale_en') . ')')->required(),
                ]),
                Grid::make(2)->schema([
                    Textarea::make('a_fa')->label(__('content.faq_answer') . ' (' . __('content.locale_fa') . ')')->required()->rows(3),
                    Textarea::make('a_en')->label(__('content.faq_answer') . ' (' . __('content.locale_en') . ')')->required()->rows(3),
                ]),
            ])
            ->itemLabel(fn (array $state): ?string => $state['q_fa'] ?? $state['q_en'] ?? null)
            ->addActionLabel(__('content.faq_add'))
            ->reorderable()
            ->collapsible();
    }

    private function imageField(string $slot, string $ratio): Section
    {
        return Section::make(__("content.image_{$slot}"))->schema([
            FileUpload::make("image_{$slot}")
                ->label('')
                ->helperText(__('content.image_help', ['kb' => LandingContent::IMAGE_MAX_KB, 'ratio' => $ratio]))
                ->image()
                ->disk(Brand::DISK)->directory(LandingContent::IMAGE_DIR . '/uploads')->visibility('public')
                ->acceptedFileTypes(LandingContent::IMAGE_MIMES)
                ->maxSize(LandingContent::IMAGE_MAX_KB)
                ->getUploadedFileNameForStorageUsing(fn ($file) => substr(hash_file('sha256', $file->getRealPath()), 0, 16) . '.' . strtolower($file->getClientOriginalExtension()))
                ->rules([fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                    if ($value instanceof \Illuminate\Http\UploadedFile
                        && ($value->getMimeType() === 'image/svg+xml' || strtolower($value->getClientOriginalExtension()) === 'svg')
                        && LandingContent::svgIsUnsafe((string) file_get_contents($value->getRealPath()))) {
                        $fail(__('content.error_unsafe_svg'));
                    }
                }]),
            Placeholder::make("image_{$slot}_default")
                ->label(__('content.image_default'))
                ->visible(fn () => LandingContent::imagePath($slot) === null)
                ->content(new HtmlString('<img src="' . e(LandingContent::defaultImageUrl($slot)) . '" alt="" style="max-height:160px;border-radius:12px;border:1px solid #e5e7eb">')),
        ])->columns(2)->compact();
    }

    /** The existing human labels where they exist; otherwise the key itself (always shown in the hint). */
    private function fieldLabel(string $key): string
    {
        if (Lang::has("content.field_{$key}")) return __("content.field_{$key}");
        if (preg_match('/^feature(\d)_(title|body)$/', $key, $m)) {
            return __('content.feature_n', ['n' => $m[1]]) . ' — ' . __("content.field_feature_{$m[2]}");
        }
        if (preg_match('/^integrations_step(\d)_(title|body)$/', $key, $m)) {
            return __('content.step_n', ['n' => $m[1]]) . ' — ' . __("content.field_step_{$m[2]}");
        }
        return $key;
    }

    private function bilingualField(string $key): Grid
    {
        return Grid::make(2)->schema(array_map(
            fn (string $locale) => $this->landingField($key, $locale),
            LandingContent::LOCALES,
        ));
    }

    private function landingField(string $key, string $locale)
    {
        $name = $this->landingFieldName($key, $locale);
        $max = LandingContent::maxLength($key);
        $label = $this->fieldLabel($key) . ' (' . __("content.locale_{$locale}") . ')';

        $hint = [$key, __('content.default_hint', ['value' => LandingContent::fallback($key, $locale)])];
        if (in_array($key, LandingContent::RICH_KEYS, true)) $hint[] = __('content.marker_hint');
        $hint[] = __('content.empty_hint');

        $field = $max >= LandingContent::MAX_TEXT
            ? Textarea::make($name)->rows(3)->autosize()
            : TextInput::make($name);

        return $field
            ->label($label)
            ->maxLength($max)
            ->extraInputAttributes(['dir' => $locale === 'fa' ? 'rtl' : 'ltr'])
            ->helperText(implode(' · ', $hint))
            ->rules([fn (): \Closure => function (string $attribute, $value, \Closure $fail) use ($key, $locale) {
                if ($error = LandingContent::validationError($key, $locale, is_string($value) ? $value : null)) {
                    $fail($error);
                }
            }]);
    }

    private function legalBilingualField(string $slug, string $field, string $label, bool $textarea = false): Grid
    {
        return Grid::make(2)->schema([
            $this->textField($this->legalFieldName($slug, $field, 'fa'), $label . ' (' . __('content.locale_fa') . ')', $textarea),
            $this->textField($this->legalFieldName($slug, $field, 'en'), $label . ' (' . __('content.locale_en') . ')', $textarea),
        ]);
    }

    private function textField(string $name, string $label, bool $textarea)
    {
        return $textarea
            ? Textarea::make($name)->label($label)->rows(4)
            : TextInput::make($name)->label($label);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $before = [];
        $after = [];

        foreach (LandingContent::LANDING_KEYS as $key) {
            foreach (LandingContent::LOCALES as $locale) {
                $name = $this->landingFieldName($key, $locale);
                if (!array_key_exists($name, $state)) continue;

                $old = LandingContent::get($key, $locale);
                if (LandingContent::set($key, $locale, $state[$name])) {
                    $before["landing.{$key}.{$locale}"] = $old;
                    $after["landing.{$key}.{$locale}"] = LandingContent::get($key, $locale);
                }
            }
        }

        foreach (LandingContent::IMAGE_SLOTS as $slot) {
            $name = "image_{$slot}";
            if (!array_key_exists($name, $state)) continue;

            $old = LandingContent::imageUrl($slot);
            $value = $state[$name];
            if (is_array($value)) $value = reset($value) ?: null;
            if (LandingContent::setImage($slot, $value)) {
                $before["image.{$slot}"] = $old;
                $after["image.{$slot}"] = LandingContent::imageUrl($slot);
            }
        }

        foreach (LandingContent::LEGAL_PAGES as $slug) {
            foreach (['title', 'body'] as $field) {
                foreach (LandingContent::LOCALES as $locale) {
                    $name = $this->legalFieldName($slug, $field, $locale);
                    if (!array_key_exists($name, $state)) continue;

                    $old = $field === 'title' ? LandingContent::legalTitle($slug, $locale) : LandingContent::legalBody($slug, $locale);
                    if (LandingContent::setLegal($slug, $field, $locale, $state[$name])) {
                        $before["legal.{$slug}.{$field}.{$locale}"] = $old;
                        $after["legal.{$slug}.{$field}.{$locale}"] =
                            $field === 'title' ? LandingContent::legalTitle($slug, $locale) : LandingContent::legalBody($slug, $locale);
                    }
                }
            }
        }

        $oldFaq = LandingContent::faq();
        $newFaq = $state['faq'] ?? [];
        if ($newFaq !== $oldFaq) {
            LandingContent::setFaq($newFaq);
            $before['faq'] = $oldFaq;
            $after['faq'] = LandingContent::faq();
        }

        $oldContact = LandingContent::contact();
        // Filament hands an empty field back as null, the store holds ''.
        $newContact = array_map(fn ($v) => trim((string) $v), array_merge(['address' => '', 'phone' => '', 'email' => ''], $state['contact'] ?? []));
        if ($newContact !== $oldContact) {
            LandingContent::setContact($newContact);
            $before['contact'] = $oldContact;
            $after['contact'] = LandingContent::contact();
        }

        if ($after) {
            PlatformActivity::record(
                'site_content_changed',
                subjectType: 'platform',
                subjectId: 'site_content',
                before: $before,
                after: $after,
            );
        }

        $this->form->fill($this->currentValues());

        Notification::make()->title(__('common.settings_saved'))->success()->send();
    }
}
