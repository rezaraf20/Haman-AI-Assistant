<?php
namespace App\Filament\Pages;

use App\Console\Commands\DiskReportCommand;
use App\Models\{LlmProviderProfile, Plan};
use App\Services\Payments\PaymentGatewayManager;
use App\Support\{Brand, MailSettings, PlatformAccess, PlatformActivity, Settings as Config, SettingsRegistry};
use Filament\Forms\Components\{Actions, ColorPicker, FileUpload, Grid, Placeholder, Section, Select, Tabs, TextInput, Toggle};
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Everything the platform owner can change without a deploy.
 *
 * Built from SettingsRegistry rather than a hand-written form, so a new
 * setting is one entry in one file and appears here with its default, its
 * reset button and its type already correct.
 *
 * Two rules the form has to honour:
 *
 *  - No secret is ever rendered. A secret field starts empty and shows a
 *    "configured" badge instead; submitting it empty leaves the stored value
 *    alone, so saving the page does not wipe every key on it.
 *  - Saving writes through Settings::set(), which stores only what differs
 *    from the declared default. That is what makes the reset button a delete
 *    rather than a second copy of the number.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.settings';
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool { return PlatformAccess::allows('platform_settings'); }
    public static function shouldRegisterNavigation(): bool { return PlatformAccess::allows('platform_settings'); }

    public static function getNavigationLabel(): string { return __('panel.settings_nav'); }
    public static function getNavigationGroup(): ?string { return __('panel.nav_group_infrastructure'); }
    public function getTitle(): string { return __('panel.settings_title'); }

    public ?array $data = [];
    public ?string $testEmailTo = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill($this->currentValues());
        $this->testEmailTo = auth()->user()?->email;
    }

    /** Secrets come back as null — the form never receives a stored secret. */
    private function currentValues(): array
    {
        $values = [];
        foreach (array_keys(SettingsRegistry::all()) as $key) {
            $values[$this->fieldName($key)] = Config::redactedFor($key);
        }

        // Brand fields aren't in SettingsRegistry (they're files, not
        // scalars) — fed in separately here so form()/save() can still treat
        // the whole form as one flat $state array. Only a genuinely
        // uploaded file is shown as an existing FileUpload value; the
        // shipped default is never presented as if someone had chosen it.
        $values['brand_logo']          = $this->diskPathFromUrl(Brand::hasCustomLogo() ? Brand::logoUrl() : null);
        $values['brand_mark']          = $this->diskPathFromUrl(Brand::hasCustomMark() ? Brand::markUrl() : null);
        $values['brand_mark_light']    = $this->diskPathFromUrl(Brand::hasCustomMarkLight() ? Brand::markLightUrl() : null);
        $values['brand_primary_color'] = Brand::primaryColor();

        return $values;
    }

    /** '/brand/uploads/xyz.svg' -> 'uploads/xyz.svg' — the 'brand' disk's own root already is /brand, so this is the path FileUpload's disk-relative state expects. */
    private function diskPathFromUrl(?string $url): ?string
    {
        return $url ? ltrim(str_replace('/brand/', '', $url), '/') : null;
    }

    /** Dots are Filament's nesting separator, so they cannot survive here. */
    private function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('settings')->tabs([
                Tabs\Tab::make(__('settings.tab_brand'))->icon('heroicon-o-swatch')->schema($this->brandTab()),
                Tabs\Tab::make(__('settings.tab_payments'))->icon('heroicon-o-credit-card')->schema($this->paymentsTab()),
                Tabs\Tab::make(__('settings.tab_email'))->icon('heroicon-o-envelope')->schema($this->emailTab()),
                Tabs\Tab::make(__('settings.tab_sms'))->icon('heroicon-o-device-phone-mobile')->schema($this->smsTab()),
                Tabs\Tab::make(__('settings.tab_pricing'))->icon('heroicon-o-banknotes')->schema($this->pricingTab()),
                Tabs\Tab::make(__('settings.tab_limits'))->icon('heroicon-o-adjustments-horizontal')->schema($this->limitsTab()),
                Tabs\Tab::make(__('settings.tab_system'))->icon('heroicon-o-server-stack')->schema($this->systemTab()),
            ])->persistTabInQueryString(),
        ])->statePath('data');
    }

    // ── Tab bodies ──────────────────────────────────────────────────────

    private function brandTab(): array
    {
        return [
            Section::make(__('settings.brand_identity'))
                ->description(__('settings.brand_identity_desc'))
                ->schema([
                    FileUpload::make('brand_logo')
                        ->label(__('settings.brand_logo'))
                        ->helperText(__('settings.brand_logo_help'))
                        ->disk(Brand::DISK)->directory('uploads')->visibility('public')
                        ->acceptedFileTypes(Brand::ALLOWED_MIMES)
                        ->maxSize(Brand::UPLOAD_MAX_KB)
                        ->getUploadedFileNameForStorageUsing(fn ($file) => $this->hashedBrandFilename($file))
                        ->rules([$this->minDimensionRule()])
                        ->columnSpanFull(),
                    FileUpload::make('brand_mark')
                        ->label(__('settings.brand_mark'))
                        ->helperText(__('settings.brand_mark_help'))
                        ->disk(Brand::DISK)->directory('uploads')->visibility('public')
                        ->acceptedFileTypes(Brand::ALLOWED_MIMES)
                        ->maxSize(Brand::UPLOAD_MAX_KB)
                        ->getUploadedFileNameForStorageUsing(fn ($file) => $this->hashedBrandFilename($file))
                        ->rules([$this->minDimensionRule()]),
                    FileUpload::make('brand_mark_light')
                        ->label(__('settings.brand_mark_light'))
                        ->helperText(__('settings.brand_mark_light_help'))
                        ->disk(Brand::DISK)->directory('uploads')->visibility('public')
                        ->acceptedFileTypes(Brand::ALLOWED_MIMES)
                        ->maxSize(Brand::UPLOAD_MAX_KB)
                        ->getUploadedFileNameForStorageUsing(fn ($file) => $this->hashedBrandFilename($file))
                        ->rules([$this->minDimensionRule()]),
                    ColorPicker::make('brand_primary_color')
                        ->label(__('settings.brand_primary_color'))
                        ->helperText(__('settings.brand_primary_color_help')),
                ])->columns(2),
            Placeholder::make('brand_scope_note')
                ->label('')
                ->content(__('settings.brand_scope_note')),
        ];
    }

    /** Same content-hash naming Brand::storeUpload() uses elsewhere — a re-upload of the same bytes reuses the same URL instead of piling up duplicate files. */
    private function hashedBrandFilename($file): string
    {
        $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
        return "{$hash}.{$file->getClientOriginalExtension()}";
    }

    /** Skipped for SVG, which has no intrinsic raster size to check. */
    private function minDimensionRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if (!$value instanceof \Illuminate\Http\UploadedFile) return;
            if ($value->getClientMimeType() === 'image/svg+xml') return;

            $size = @getimagesize($value->getRealPath());
            if ($size && ($size[0] < Brand::MIN_DIMENSION_PX || $size[1] < Brand::MIN_DIMENSION_PX)) {
                $fail(__('settings.brand_dimension_too_small', ['min' => Brand::MIN_DIMENSION_PX]));
            }
        };
    }

    private function paymentsTab(): array
    {
        return [
            $this->group('zarinpal', __('settings.zarinpal'), __('settings.zarinpal_desc'), [
                $this->field('payments.zarinpal.merchant_id'),
                $this->field('payments.zarinpal.sandbox'),
            ]),
            $this->group('stripe', __('settings.stripe'), __('settings.stripe_desc'), [
                $this->field('payments.stripe.publishable_key'),
                $this->field('payments.stripe.secret_key'),
                $this->field('payments.stripe.webhook_secret'),
            ]),
            $this->group('paddle', __('settings.paddle'), __('settings.paddle_desc'), [
                $this->field('payments.paddle.vendor_id'),
                $this->field('payments.paddle.api_key'),
                $this->field('payments.paddle.webhook_secret'),
                $this->field('payments.paddle.sandbox'),
            ]),
            Section::make(__('settings.routing'))
                ->description(__('settings.routing_desc'))
                ->schema([
                    Grid::make(3)->schema([
                        $this->field('payments.gateway.IRT'),
                        $this->field('payments.gateway.USD'),
                        $this->field('payments.gateway.EUR'),
                    ]),
                ]),
            Section::make(__('settings.fx'))
                ->description(__('settings.fx_desc'))
                ->schema([
                    $this->field('payments.fx.mode'),
                    $this->field('payments.fx.usd_to_toman'),
                    $this->field('payments.fx.eur_to_toman'),
                    $this->field('payments.fx.source_url'),
                ])->columns(2),
        ];
    }

    private function emailTab(): array
    {
        return [
            $this->group('email', __('settings.smtp'), __('settings.smtp_desc'), [
                $this->field('mail.host'),
                $this->field('mail.port'),
                $this->field('mail.encryption'),
                $this->field('mail.username'),
                $this->field('mail.password'),
                $this->field('mail.from_address'),
                $this->field('mail.from_name'),
            ]),
            Section::make(__('settings.test_email'))
                ->description(__('settings.test_email_desc'))
                ->schema([
                    TextInput::make('testEmailTo')
                        ->label(__('settings.test_email_to'))
                        ->email()
                        ->helperText(__('settings.test_email_help')),
                    Actions::make([
                        FormAction::make('sendTestEmail')
                            ->label(__('settings.send_test_email'))
                            ->icon('heroicon-o-paper-airplane')
                            ->disabled(fn () => !MailSettings::isUsable())
                            ->action('sendTestEmail'),
                    ]),
                    Placeholder::make('email_disabled_note')
                        ->label('')
                        ->visible(fn () => !MailSettings::isUsable())
                        ->content(__('settings.email_disabled_note')),
                ]),
        ];
    }

    private function smsTab(): array
    {
        return [
            Section::make(__('settings.sms_provider'))
                ->description(__('settings.sms_provider_desc'))
                ->schema([$this->field('sms.provider')]),
            $this->group('melipayamak', __('settings.melipayamak'), __('settings.melipayamak_desc'), [
                $this->field('sms.melipayamak.username'),
                $this->field('sms.melipayamak.password'),
                $this->field('sms.melipayamak.sender'),
                $this->field('sms.melipayamak.use_pattern'),
                $this->field('sms.melipayamak.pattern_id'),
            ]),
            Section::make(__('settings.sms_cost_and_caps'))
                ->description(__('settings.sms_caps_desc'))
                ->schema([
                    $this->field('sms.cost_toman'),
                    $this->field('sms.daily_cap_per_tenant'),
                    $this->field('sms.daily_cap_platform'),
                ])->columns(3),
        ];
    }

    private function pricingTab(): array
    {
        return [
            Section::make(__('settings.pricing_defaults'))
                ->schema([
                    $this->field('pricing.default_currency'),
                    $this->field('pricing.default_margin_multiplier'),
                    $this->field('pricing.embedding_cost_per_1m_toman'),
                ])->columns(3),

            Section::make(__('settings.pricing_popular_plan'))
                ->description(__('settings.pricing_popular_plan_desc'))
                ->schema([
                    // Not $this->field(): the choices are published plans, a
                    // dynamic list of rows, not the fixed enum that helper's
                    // 'select' branch expects. Same submitted field name
                    // (pricing__popular_plan_slug) though, so save() — which
                    // just walks SettingsRegistry keys generically — picks
                    // this up with no changes of its own.
                    Select::make($this->fieldName('pricing.popular_plan_slug'))
                        ->label(__('settings.field_pricing_popular_plan_slug'))
                        ->options(fn () => Plan::where('is_public', true)
                            ->orderBy('sort_order')
                            ->pluck('name', 'slug'))
                        ->placeholder(__('settings.pricing_popular_plan_none'))
                        ->native(false),
                ]),

            // Token prices live on each LLM profile and are edited there.
            // Shown here read-only because "what does a million tokens cost"
            // is a pricing question, and sending someone to another screen to
            // answer it is how a number gets changed in one place only.
            Section::make(__('settings.model_token_prices'))
                ->description(__('settings.model_token_prices_desc'))
                ->schema([
                    Placeholder::make('token_prices')
                        ->label('')
                        ->content(fn () => view('filament.pages.partials.token-prices', [
                            'profiles' => LlmProviderProfile::orderBy('name')->get(),
                        ])),
                ]),
        ];
    }

    private function limitsTab(): array
    {
        $groups = [
            'chat'      => __('settings.limits_chat'),
            'tools'     => __('settings.limits_tools'),
            'otp'       => __('settings.limits_otp'),
            'documents' => __('settings.limits_documents'),
            'retrieval' => __('settings.limits_retrieval'),
        ];

        $sections = [];
        foreach ($groups as $group => $label) {
            $keys = array_filter(
                SettingsRegistry::keysForTab('limits'),
                fn ($key) => SettingsRegistry::definition($key)['group'] === $group,
            );

            $sections[] = Section::make($label)
                ->description(__('settings.limits_group_desc'))
                ->schema(array_map(fn ($key) => $this->field($key), array_values($keys)))
                ->columns(2);
        }

        return $sections;
    }

    private function systemTab(): array
    {
        return [
            Section::make(__('settings.backup'))
                ->description(__('settings.backup_desc'))
                ->icon(Config::isConfigured('backup') ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
                ->iconColor(Config::isConfigured('backup') ? 'success' : 'warning')
                ->schema([
                    Placeholder::make('backup_status')
                        ->label('')
                        ->content(fn () => view('filament.pages.partials.backup-status', [
                            'latest'   => \App\Models\BackupRun::latestAttempt(),
                            'success'  => \App\Models\BackupRun::latestSuccess(),
                            'verified' => \App\Models\BackupRun::latestVerified(),
                            'offsite'  => Config::get('backup.destination') === 's3' && Config::isConfigured('backup'),
                        ])),
                    $this->field('backup.destination'),
                    $this->field('backup.s3.endpoint'),
                    $this->field('backup.s3.region'),
                    $this->field('backup.s3.bucket'),
                    $this->field('backup.s3.access_key'),
                    $this->field('backup.s3.secret_key'),
                    $this->field('backup.s3.prefix'),
                    $this->field('backup.keep_daily'),
                    $this->field('backup.keep_weekly'),
                ])->columns(2),

            Section::make(__('settings.system_status'))
                ->description(__('settings.system_status_desc'))
                ->schema([
                    Placeholder::make('health')
                        ->label('')
                        ->content(fn () => view('filament.pages.partials.system-health', [
                            'checks' => $this->healthChecks(),
                        ])),
                ]),
            Section::make(__('settings.disk_usage'))
                ->description(__('settings.disk_usage_desc'))
                ->schema([
                    Placeholder::make('disk_usage')
                        ->label('')
                        ->content(fn () => view('filament.pages.partials.disk-usage', [
                            'report' => $this->diskUsageReport(),
                        ])),
                    $this->field('system.disk_warn_percent'),
                ]),
            Section::make(__('settings.retention'))
                ->description(__('settings.retention_desc'))
                ->schema([
                    $this->field('system.retention_event_payload_days'),
                    $this->field('system.retention_activity_log_months'),
                ])->columns(2),
            Section::make(__('settings.maintenance'))
                ->description(__('settings.maintenance_desc'))
                ->schema([
                    $this->field('system.maintenance_mode'),
                    $this->field('system.maintenance_message'),
                ]),
        ];
    }

    // ── Field construction ──────────────────────────────────────────────

    /** A section with a configured/not-configured badge in its heading. */
    private function group(string $group, string $label, string $description, array $fields): Section
    {
        $configured = Config::isConfigured($group);

        return Section::make($label)
            ->description($description)
            ->icon($configured ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
            ->iconColor($configured ? 'success' : 'warning')
            ->headerActions([
                FormAction::make('status_' . $group)
                    ->label($configured ? __('settings.configured') : __('settings.not_configured'))
                    ->color($configured ? 'success' : 'warning')
                    ->disabled()
                    ->badge(),
            ])
            ->schema($fields)
            ->columns(2);
    }

    private function field(string $key)
    {
        $definition = SettingsRegistry::definition($key);
        $name = $this->fieldName($key);
        $label = __('settings.field_' . str_replace('.', '_', $key));

        $component = match ($definition['type']) {
            'bool' => Toggle::make($name)->label($label),

            'select' => Select::make($name)
                ->label($label)
                ->options(collect($definition['options'])
                    ->mapWithKeys(fn ($option) => [$option => __('settings.option_' . $option)])
                    ->all())
                ->selectablePlaceholder(false),

            'secret' => TextInput::make($name)
                ->label($label)
                ->password()
                ->autocomplete('new-password')
                // The stored value is never sent to the browser. Leaving this
                // blank keeps whatever is already saved; typing replaces it.
                ->placeholder(Config::isSet($key)
                    ? __('settings.secret_set_placeholder')
                    : __('settings.secret_unset_placeholder'))
                ->helperText(Config::isSet($key)
                    ? __('settings.secret_set_help')
                    : __('settings.secret_unset_help')),

            'int' => TextInput::make($name)->label($label)->numeric()->minValue(0),

            'float' => TextInput::make($name)->label($label)->numeric()->minValue(0)->step(0.01),

            default => TextInput::make($name)->label($label)->maxLength(500),
        };

        // Anything with a meaningful default gets a reset button that puts
        // exactly that number back.
        if ($definition['default'] !== null && $definition['type'] !== 'secret') {
            $component = $component->hintAction(
                FormAction::make('reset_' . $name)
                    ->label(__('settings.reset_to_default', ['value' => $this->displayDefault($definition)]))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn () => Config::isOverridden($key))
                    ->action(function () use ($key, $name) {
                        Config::reset($key);
                        $this->data[$name] = Config::redactedFor($key);
                        Notification::make()->title(__('settings.reset_done'))->success()->send();
                    }),
            );
        }

        return $component;
    }

    private function displayDefault(array $definition): string
    {
        return match ($definition['type']) {
            'bool'  => $definition['default'] ? __('common.yes') : __('common.no'),
            default => (string) $definition['default'],
        };
    }

    // ── Actions ─────────────────────────────────────────────────────────

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $before = [];
        $after = [];

        foreach (array_keys(SettingsRegistry::all()) as $key) {
            $name = $this->fieldName($key);
            if (!array_key_exists($name, $state)) continue;

            $value = $state[$name];

            // An empty secret field means "leave it as it is", not "clear
            // it" — otherwise every save would wipe every key on the page.
            if (SettingsRegistry::isSecret($key) && ($value === null || $value === '')) {
                continue;
            }

            // A secret reads back as null either way, so "did it change"
            // cannot be answered by comparing values. Anything that reaches
            // here with a non-empty secret IS a deliberate change, because
            // the empty case was skipped above.
            if (SettingsRegistry::isSecret($key)) {
                $wasSet = Config::isSet($key);
                Config::set($key, $value);

                $before[$key] = $wasSet ? '***' : null;
                $after[$key]  = '***';
                continue;
            }

            $old = Config::redactedFor($key);
            Config::set($key, $value);
            $new = Config::redactedFor($key);

            if ($old !== $new) {
                $before[$key] = $old;
                $after[$key]  = $new;
            }
        }

        if ($after) {
            PlatformActivity::record(
                'platform_settings_changed',
                subjectType: 'platform',
                subjectId: 'settings',
                before: $before,
                after: $after,
            );
        }

        $this->saveBrand($state);

        // A changed SMTP host has to reach the mailer without a restart.
        MailSettings::apply();

        $this->form->fill($this->currentValues());

        Notification::make()->title(__('common.settings_saved'))->success()->send();
    }

    /**
     * FileUpload's disk-relative state, when it changed, is turned into the
     * /brand/... URL Brand::set() stores and everywhere else reads. The old
     * file is deleted whenever it's actually being replaced or cleared —
     * never on an untouched field, where old === new and nothing happens.
     */
    private function saveBrand(array $state): void
    {
        $brandBefore = Brand::all();
        $brandAfter = [];

        $fileFields = ['brand_logo' => 'logo_url', 'brand_mark' => 'mark_url', 'brand_mark_light' => 'mark_light_url'];
        foreach ($fileFields as $field => $key) {
            if (!array_key_exists($field, $state)) continue;

            $newPath = $state[$field];
            $newUrl = $newPath ? '/brand/' . ltrim((string) $newPath, '/') : null;
            $oldUrl = $brandBefore[$key];
            if ($newUrl === $oldUrl) continue;

            Brand::deleteUpload($oldUrl);
            Brand::set($key, $newUrl);
            $brandAfter[$key] = $newUrl;
        }

        if (array_key_exists('brand_primary_color', $state)) {
            $newColor = $state['brand_primary_color'] ?: null;
            if ($newColor !== $brandBefore['primary_color']) {
                Brand::set('primary_color', $newColor);
                $brandAfter['primary_color'] = $newColor;
            }
        }

        if ($brandAfter) {
            PlatformActivity::record(
                'brand_changed',
                subjectType: 'platform',
                subjectId: 'brand',
                before: array_intersect_key($brandBefore, $brandAfter),
                after: $brandAfter,
            );
        }
    }

    public function sendTestEmail(): void
    {
        abort_unless(static::canAccess(), 403);

        $result = MailSettings::sendTest($this->testEmailTo ?: (string) auth()->user()?->email);

        Notification::make()
            ->title($result['ok'] ? __('settings.test_email_ok') : __('settings.test_email_failed'))
            ->body($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->persistent()
            ->send();
    }

    /**
     * The System tab's view of haman:disk-report — same numbers, same
     * warn-percent config, formatted for display instead of a CLI table.
     */
    public function diskUsageReport(): array
    {
        $report = DiskReportCommand::build((int) Config::get('system.disk_warn_percent', 85));

        return $report + [
            'logs_human'       => DiskReportCommand::formatBytes($report['logs_bytes']),
            'backups_human'    => DiskReportCommand::formatBytes($report['backups_bytes']),
            'disk_used_human'  => DiskReportCommand::formatBytes($report['disk_used']),
            'disk_total_human' => DiskReportCommand::formatBytes($report['disk_total']),
        ];
    }

    /**
     * Live status for the system tab. Each check is wrapped on its own so
     * one dead dependency reports as dead instead of blanking the page.
     */
    public function healthChecks(): array
    {
        $checks = [];

        $checks['database'] = $this->check(function () {
            DB::select('SELECT 1');
            return __('settings.health_connected');
        });

        $checks['redis'] = $this->check(function () {
            \Illuminate\Support\Facades\Redis::ping();
            return __('settings.health_connected');
        });

        $checks['queue'] = $this->check(function () {
            $pending = DB::table('jobs')->count();
            return __('settings.health_jobs_pending', ['count' => $pending]);
        });

        // Delegated rather than reimplemented: AiGatewayService already knows
        // the path and that the call is authenticated. A second, slightly
        // different idea of how to reach that service is how a probe ends up
        // reporting on something the app never calls.
        $checks['python'] = $this->check(function () {
            $result = app(\App\Services\AiGatewayService::class)->healthCheck();

            if (($result['status'] ?? 'down') === 'down') {
                throw new \RuntimeException($result['error'] ?? __('settings.health_unreachable'));
            }

            return $result['status'] ?? __('settings.health_ok');
        });

        // Read off the wire rather than from the certificate files, because
        // the failure worth catching is a renewal that wrote a new file and
        // never got Apache to serve it. See CertificateExpiry.
        $checks['certificates'] = $this->check(function () {
            $certs = \App\Support\CertificateExpiry::all();

            if (!$certs) throw new \RuntimeException(__('settings.health_unreachable'));

            $problems = array_filter($certs, fn ($c) => !$c['ok']);

            if ($problems) {
                throw new \RuntimeException(implode(' · ', array_map(
                    fn ($c) => $c['error']
                        ? "{$c['host']}: {$c['error']}"
                        : __('settings.cert_days_short', ['host' => $c['host'], 'days' => $c['days']]),
                    $problems,
                )));
            }

            $soonest = min(array_column($certs, 'days'));

            return __('settings.cert_all_valid', ['count' => count($certs), 'days' => $soonest]);
        });

        return $checks;
    }

    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'message' => $probe()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function gatewayStatus(): array
    {
        $status = [];
        foreach (app(PaymentGatewayManager::class)->all() as $key => $gateway) {
            $status[$key] = $gateway->isConfigured();
        }
        return $status;
    }
}
