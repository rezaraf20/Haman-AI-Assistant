<?php
namespace App\Filament\Pages;

use App\Support\{LandingContent, PlatformAccess, PlatformActivity};
use Filament\Forms\Components\{Grid, Repeater, Section, Tabs, Textarea, TextInput};
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Editable marketing/legal copy for the public site, one tab per section —
 * see LandingContent for how a save here reaches the live page (Laravel's
 * own translator, patched at boot; no cache of this page's own to clear).
 *
 * Scope is deliberately the four sections asked for plus FAQ, legal pages
 * and contact info — not the whole of lang/landing.php. Nav labels, the
 * live conversation demo, pricing/signup copy and footer boilerplate are UI
 * chrome that changes together with the page's structure, not prose an
 * admin edits on its own; see LandingContent::LANDING_KEYS for exactly
 * what is and isn't here.
 *
 * Every text field is fa/en side by side rather than one field per locale
 * tab, so a translation gap between the two is visible immediately instead
 * of requiring a tab switch to notice one language was never filled in.
 */
class SiteContent extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.site-content';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 11;

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
        return $form->schema([
            Tabs::make('content')->tabs([
                Tabs\Tab::make(__('content.tab_hero'))->schema([
                    $this->bilingualField('hero_title', __('content.field_hero_title')),
                    $this->bilingualField('hero_subtitle', __('content.field_hero_subtitle'), textarea: true),
                ]),

                Tabs\Tab::make(__('content.tab_features'))->schema([
                    $this->bilingualField('features_title', __('content.field_features_title')),
                    $this->bilingualField('features_subtitle', __('content.field_features_subtitle'), textarea: true),
                    ...collect(range(1, 6))->map(fn ($n) => Section::make(__('content.feature_n', ['n' => $n]))->schema([
                        $this->bilingualField("feature{$n}_title", __('content.field_feature_title')),
                        $this->bilingualField("feature{$n}_body", __('content.field_feature_body'), textarea: true),
                    ])->collapsible())->all(),
                ]),

                Tabs\Tab::make(__('content.tab_how_it_works'))->schema([
                    $this->bilingualField('integrations_title', __('content.field_integrations_title')),
                    $this->bilingualField('integrations_subtitle', __('content.field_integrations_subtitle'), textarea: true),
                    ...collect(range(1, 3))->map(fn ($n) => Section::make(__('content.step_n', ['n' => $n]))->schema([
                        $this->bilingualField("integrations_step{$n}_title", __('content.field_step_title')),
                        $this->bilingualField("integrations_step{$n}_body", __('content.field_step_body'), textarea: true),
                    ])->collapsible())->all(),
                ]),

                Tabs\Tab::make(__('content.tab_final_cta'))->schema([
                    $this->bilingualField('final_cta_title', __('content.field_final_cta_title')),
                    $this->bilingualField('final_cta_subtitle', __('content.field_final_cta_subtitle'), textarea: true),
                    $this->bilingualField('final_cta_button', __('content.field_final_cta_button')),
                ]),

                Tabs\Tab::make(__('content.tab_faq'))->schema([
                    Repeater::make('faq')
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
                        ->collapsible(),
                ]),

                Tabs\Tab::make(__('content.tab_legal'))->schema(
                    collect(LandingContent::LEGAL_PAGES)->map(fn ($slug) =>
                        Section::make(__("content.legal_{$slug}"))->schema([
                            $this->legalBilingualField($slug, 'title', __('content.field_legal_title')),
                            $this->legalBilingualField($slug, 'body', __('content.field_legal_body'), textarea: true),
                        ])->collapsible()
                    )->all()
                ),

                Tabs\Tab::make(__('content.tab_contact'))->schema([
                    Section::make(__('content.tab_contact'))
                        ->description(__('content.contact_desc'))
                        ->schema([
                            TextInput::make('contact.address')->label(__('content.field_contact_address')),
                            TextInput::make('contact.phone')->label(__('content.field_contact_phone')),
                            TextInput::make('contact.email')->label(__('content.field_contact_email'))->email(),
                        ]),
                ]),
            ])->persistTabInQueryString(),
        ])->statePath('data');
    }

    private function bilingualField(string $key, string $label, bool $textarea = false): Grid
    {
        return Grid::make(2)->schema([
            $this->textField($this->landingFieldName($key, 'fa'), $label . ' (' . __('content.locale_fa') . ')', $textarea),
            $this->textField($this->landingFieldName($key, 'en'), $label . ' (' . __('content.locale_en') . ')', $textarea),
        ]);
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
        $newContact = $state['contact'] ?? [];
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
