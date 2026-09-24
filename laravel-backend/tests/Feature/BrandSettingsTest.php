<?php
namespace Tests\Feature;

use App\Filament\Pages\Settings as SettingsPage;
use App\Models\User;
use App\Support\{Brand, Settings};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin Settings page's Brand tab (Settings > Brand) — the platform's
 * own logo/mark/color, previously only changeable with a code deploy.
 *
 * Real incident behind this: check-widget-brand-defaults.php exists because
 * the widget's hardcoded fallback color drifted from config('haman.brand.*')
 * for months. That check still guards the plugin's own static fallback —
 * untouched here — but everything downstream of Brand::primaryColor() (the
 * panels, the landing page, the live widget config) now reads one runtime
 * value instead of a constant, so this suite is the one proving a saved
 * Brand change actually reaches all of them, not just the settings row.
 */
class BrandSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
        Storage::fake(Brand::DISK);
    }

    private function admin(): User
    {
        $user = User::create([
            'email' => Str::random(10) . '@example.test',
            'password' => bcrypt('x'), 'password_hash' => bcrypt('x'),
            'name' => 'Admin', 'role' => 'owner', 'email_verified_at' => now(),
        ]);
        $user->platform_role = 'admin';
        $user->platform_is_active = true;
        $user->save();

        return $user;
    }

    // ── Defaults, before anything is uploaded ───────────────────────────

    public function test_with_nothing_uploaded_every_field_falls_back_to_the_shipped_default(): void
    {
        $this->assertFalse(Brand::hasCustomLogo());
        $this->assertNull(Brand::logoUrl());
        $this->assertFalse(Brand::hasCustomMark());
        $this->assertSame('/brand/hamanai-mark.svg', Brand::markUrl());
        $this->assertFalse(Brand::hasCustomMarkLight());
        $this->assertSame('/brand/hamanai-mark-light.svg', Brand::markLightUrl());
        $this->assertSame(config('haman.brand.primary_color'), Brand::primaryColor());
        $this->assertSame('image/svg+xml', Brand::markMimeType());
    }

    // ── Brand class storage ──────────────────────────────────────────────

    public function test_storing_a_file_content_hashes_the_filename_and_is_retrievable_by_url(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.svg', '<svg>x</svg>');

        $url = Brand::storeUpload($file);

        $this->assertStringStartsWith('/brand/uploads/', $url);
        $this->assertStringEndsWith('.svg', $url);
        Storage::disk(Brand::DISK)->assertExists(str_replace('/brand/', '', $url));
    }

    public function test_re_uploading_identical_bytes_reuses_the_same_url(): void
    {
        $a = UploadedFile::fake()->createWithContent('logo.svg', '<svg>same</svg>');
        $b = UploadedFile::fake()->createWithContent('logo-renamed.svg', '<svg>same</svg>');

        $this->assertSame(Brand::storeUpload($a), Brand::storeUpload($b));
    }

    public function test_set_and_get_round_trip_through_platform_settings(): void
    {
        Brand::set('primary_color', '#123456');

        $this->assertSame('#123456', Brand::primaryColor());
        $this->assertSame('#123456', Brand::all()['primary_color']);
    }

    public function test_setting_a_key_to_null_clears_the_override(): void
    {
        Brand::set('mark_url', '/brand/uploads/abc123.svg');
        $this->assertTrue(Brand::hasCustomMark());

        Brand::set('mark_url', null);

        $this->assertFalse(Brand::hasCustomMark());
        $this->assertSame('/brand/hamanai-mark.svg', Brand::markUrl());
    }

    public function test_delete_upload_is_a_no_op_for_the_default_url(): void
    {
        // Must not attempt to delete the shipped default file from the disk.
        Brand::deleteUpload('/brand/hamanai-mark.svg');
        Brand::deleteUpload(null);

        $this->assertTrue(true); // No exception, nothing thrown for a non-upload URL.
    }

    public function test_delete_upload_removes_a_previously_stored_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.svg', '<svg>gone-soon</svg>');
        $url = Brand::storeUpload($file);
        $path = str_replace('/brand/', '', $url);
        Storage::disk(Brand::DISK)->assertExists($path);

        Brand::deleteUpload($url);

        Storage::disk(Brand::DISK)->assertMissing($path);
    }

    // ── The Settings page's Brand tab ────────────────────────────────────

    public function test_saving_a_new_mark_sets_it_and_records_activity(): void
    {
        $this->actingAs($this->admin(), 'web');

        $file = UploadedFile::fake()->createWithContent('mark.svg', '<svg>new-mark</svg>');
        Storage::disk(Brand::DISK)->putFileAs('uploads', $file, 'newmark123.svg');

        $page = new SettingsPage();
        $page->mount();
        $page->data['brand_mark'] = 'uploads/newmark123.svg';
        $page->save();

        $this->assertTrue(Brand::hasCustomMark());
        $this->assertSame('/brand/uploads/newmark123.svg', Brand::markUrl());

        $row = DB::table('platform_activity_log')->where('action', 'brand_changed')->first();
        $this->assertNotNull($row, 'A brand change is a platform action like any other.');
        $this->assertSame('/brand/uploads/newmark123.svg', json_decode($row->after, true)['mark_url']);
    }

    public function test_replacing_an_uploaded_file_deletes_the_old_one(): void
    {
        $this->actingAs($this->admin(), 'web');

        Storage::disk(Brand::DISK)->put('uploads/old111.svg', '<svg>old</svg>');
        Brand::set('mark_url', '/brand/uploads/old111.svg');
        Storage::disk(Brand::DISK)->put('uploads/new222.svg', '<svg>new</svg>');

        $page = new SettingsPage();
        $page->mount();
        $this->assertSame('uploads/old111.svg', $page->data['brand_mark'],
            'The existing upload must be pre-filled so the admin sees what is currently set.');

        $page->data['brand_mark'] = 'uploads/new222.svg';
        $page->save();

        Storage::disk(Brand::DISK)->assertMissing('uploads/old111.svg');
        Storage::disk(Brand::DISK)->assertExists('uploads/new222.svg');
        $this->assertSame('/brand/uploads/new222.svg', Brand::markUrl());
    }

    public function test_clearing_an_uploaded_field_reverts_to_the_default_and_deletes_the_file(): void
    {
        $this->actingAs($this->admin(), 'web');

        Storage::disk(Brand::DISK)->put('uploads/toclear.svg', '<svg>x</svg>');
        Brand::set('logo_url', '/brand/uploads/toclear.svg');

        $page = new SettingsPage();
        $page->mount();
        $page->data['brand_logo'] = null;
        $page->save();

        $this->assertFalse(Brand::hasCustomLogo());
        Storage::disk(Brand::DISK)->assertMissing('uploads/toclear.svg');
    }

    public function test_leaving_brand_fields_untouched_does_not_delete_the_existing_file_or_log_a_change(): void
    {
        $this->actingAs($this->admin(), 'web');

        Storage::disk(Brand::DISK)->put('uploads/keep.svg', '<svg>keep</svg>');
        Brand::set('mark_url', '/brand/uploads/keep.svg');

        $page = new SettingsPage();
        $page->mount();
        // Nothing in the brand fields changes — only an unrelated field does.
        $page->data['limits__pdf_max_pages'] = 55;
        $page->save();

        Storage::disk(Brand::DISK)->assertExists('uploads/keep.svg');
        $this->assertSame('/brand/uploads/keep.svg', Brand::markUrl());
        $this->assertNull(DB::table('platform_activity_log')->where('action', 'brand_changed')->first());
    }

    public function test_saving_a_primary_color_change_is_recorded(): void
    {
        $this->actingAs($this->admin(), 'web');

        $page = new SettingsPage();
        $page->mount();
        $page->data['brand_primary_color'] = '#ABCDEF';
        $page->save();

        $this->assertSame('#ABCDEF', Brand::primaryColor());

        $row = DB::table('platform_activity_log')->where('action', 'brand_changed')->first();
        $this->assertSame('#ABCDEF', json_decode($row->after, true)['primary_color']);
    }

    // ── Reaching the rest of the app ─────────────────────────────────────

    public function test_the_widget_config_carries_the_uploaded_mark_urls(): void
    {
        Brand::set('mark_url', '/brand/uploads/wmark.svg');
        Brand::set('mark_light_url', '/brand/uploads/wmarklight.svg');

        $merged = \App\Support\WidgetDefaults::merge($this->chatbotFixture());

        $this->assertStringContainsString('/brand/uploads/wmark.svg', $merged['powered_by_mark_url']);
        $this->assertStringContainsString('/brand/uploads/wmarklight.svg', $merged['powered_by_mark_light_url']);
    }

    public function test_the_widget_primary_color_default_follows_the_brand_override(): void
    {
        Brand::set('primary_color', '#FEDCBA');

        $merged = \App\Support\WidgetDefaults::merge($this->chatbotFixture());

        $this->assertSame('#FEDCBA', $merged['primary_color']);
    }

    public function test_the_brand_logo_partial_renders_the_upload_when_set(): void
    {
        Brand::set('logo_url', '/brand/uploads/panellogo.svg');

        $html = view('partials.brand-logo', ['height' => 28])->render();

        $this->assertStringContainsString('/brand/uploads/panellogo.svg', $html);
        $this->assertStringNotContainsString('brand-logo-mark-color', $html,
            'The default inline SVG must not also render once a custom logo is set.');
    }

    public function test_the_brand_logo_partial_renders_the_default_inline_svg_when_nothing_uploaded(): void
    {
        $html = view('partials.brand-logo', ['height' => 28])->render();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    private function chatbotFixture(): \App\Models\Tenant\Chatbot
    {
        return new \App\Models\Tenant\Chatbot([
            'id' => (string) Str::uuid(), 'name' => 'Bot', 'language' => 'en',
            'widget_config' => [],
        ]);
    }
}
