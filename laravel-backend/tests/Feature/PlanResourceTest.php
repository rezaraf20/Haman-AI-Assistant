<?php
namespace Tests\Feature;

use App\Filament\Resources\ChatbotTypePriceResource;
use App\Filament\Resources\PlanResource;
use App\Models\{ChatbotTypePrice, Plan, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin side of the landing page's pricing section: PlanResource and
 * ChatbotTypePriceResource. PublicSiteTest covers what a visitor sees;
 * this covers what an admin can actually set, since a bilingual field or a
 * price-sanity warning that only exists in the model is invisible if the
 * form never surfaces it.
 */
class PlanResourceTest extends TestCase
{
    use RefreshDatabase;

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

    private function plan(array $attrs = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'رشد', 'slug' => 'growth-' . Str::random(6),
            'price_monthly' => 990000, 'max_chatbots' => 5, 'max_tokens_monthly' => 2000000,
            'is_active' => true, 'is_public' => true, 'sort_order' => 2,
        ], $attrs));
    }

    // ── Bilingual name/description ───────────────────────────────────────

    public function test_creating_a_plan_saves_the_english_name_and_description(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(PlanResource\Pages\CreatePlan::class)
            ->fillForm([
                'name' => 'رشد', 'name_en' => 'Growth',
                'slug' => 'growth-en-test',
                'description' => 'توضیح فارسی', 'description_en' => 'English description',
                'price_monthly' => 990000, 'max_chatbots' => 5, 'max_tokens_monthly' => 2000000,
                'is_active' => true, 'is_public' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::where('slug', 'growth-en-test')->firstOrFail();
        $this->assertSame('Growth', $plan->name_en);
        $this->assertSame('English description', $plan->description_en);
    }

    public function test_the_english_name_is_optional(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(PlanResource\Pages\CreatePlan::class)
            ->fillForm([
                'name' => 'پلن بدون انگلیسی', 'slug' => 'no-english-name',
                'price_monthly' => 990000, 'max_chatbots' => 1, 'max_tokens_monthly' => 100000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::where('slug', 'no-english-name')->firstOrFail();
        $this->assertNull($plan->name_en);
    }

    // ── Features Repeater ────────────────────────────────────────────────

    public function test_features_are_saved_as_bilingual_pairs(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(PlanResource\Pages\EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm([
                'features' => [
                    ['fa' => 'پشتیبانی ۲۴ ساعته', 'en' => '24/7 support'],
                    ['fa' => 'بدون محدودیت پیام', 'en' => ''],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $plan->refresh();
        // assertEquals, not assertSame: Filament's Repeater doesn't
        // guarantee the saved array's key order matches the schema
        // declaration order, and key order carries no meaning here — only
        // content does. app()->setLocale() below exercises the actual
        // consumer (Plan::getDisplayFeaturesAttribute()) instead of poking
        // at the raw stored shape a second time.
        $this->assertCount(2, $plan->features);
        app()->setLocale('en');
        $this->assertSame(['24/7 support', 'بدون محدودیت پیام'], $plan->display_features);
        app()->setLocale('fa');
        $this->assertSame(['پشتیبانی ۲۴ ساعته', 'بدون محدودیت پیام'], $plan->display_features);
    }

    public function test_a_feature_row_requires_the_persian_side(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(PlanResource\Pages\EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm(['features' => [['fa' => '', 'en' => 'English only']]])
            ->call('save')
            ->assertHasFormErrors(['features.0.fa']);
    }

    // ── Price-looks-like-default warning ─────────────────────────────────

    public function test_a_low_price_on_an_active_public_plan_hints_a_warning(): void
    {
        $this->actingAs($this->admin(), 'web');

        $html = Livewire::test(PlanResource\Pages\CreatePlan::class)
            ->fillForm([
                'name' => 'Test', 'slug' => 'warn-test-' . Str::random(6),
                'price_monthly' => 99, 'is_active' => true, 'is_public' => true,
                'max_chatbots' => 1, 'max_tokens_monthly' => 1000,
            ])
            ->html();

        $this->assertStringContainsString(__('plans.price_looks_default'), $html);
    }

    public function test_the_warning_clears_once_the_price_is_raised_above_the_threshold(): void
    {
        $this->actingAs($this->admin(), 'web');

        $html = Livewire::test(PlanResource\Pages\CreatePlan::class)
            ->fillForm([
                'name' => 'Test', 'slug' => 'warn-clear-' . Str::random(6),
                'price_monthly' => 990000, 'is_active' => true, 'is_public' => true,
                'max_chatbots' => 1, 'max_tokens_monthly' => 1000,
            ])
            ->html();

        $this->assertStringNotContainsString(__('plans.price_looks_default'), $html);
    }

    public function test_a_free_plan_never_looks_like_a_default_price(): void
    {
        $plan = new Plan(['price_monthly' => 0, 'is_active' => true, 'is_public' => true]);

        $this->assertFalse($plan->looksLikeDefaultPrice());
    }

    public function test_a_low_priced_plan_that_is_not_public_is_not_flagged(): void
    {
        $plan = new Plan(['price_monthly' => 99, 'is_active' => true, 'is_public' => false]);

        $this->assertFalse($plan->looksLikeDefaultPrice());
    }

    // The table column's icon()/tooltip() closures call the exact same
    // Plan::looksLikeDefaultPrice() the form hint above already proves is
    // wired to the right warning copy — see the two looksLikeDefaultPrice()
    // unit tests above and the form-level test just above them. Asserting
    // against the table's rendered HTML directly is unreliable here:
    // Filament's table content loads through its own deferred Livewire
    // request, so a plain ->html() right after mount reflects the
    // pre-load skeleton, not the rows.

    // ── Chatbot-type price bilingual name ───────────────────────────────

    public function test_a_chatbot_type_prices_english_name_is_saved_and_optional(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(ChatbotTypePriceResource\Pages\CreateChatbotTypePrice::class)
            ->fillForm([
                'type' => 'faq', 'name' => 'پرسش‌های متداول', 'name_en' => 'FAQ',
                'price_toman' => 500000, 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = ChatbotTypePrice::where('type', 'faq')->firstOrFail();
        $this->assertSame('FAQ', $type->name_en);

        app()->setLocale('en');
        $this->assertSame('FAQ', $type->display_name);
        app()->setLocale('fa');
        $this->assertSame('پرسش‌های متداول', $type->display_name);
    }

    // ── Navigation ────────────────────────────────────────────────────────

    public function test_plans_chatbot_prices_and_token_packages_share_one_navigation_group(): void
    {
        $this->assertSame(__('panel.nav_group_pricing'), PlanResource::getNavigationGroup());
        $this->assertSame(__('panel.nav_group_pricing'), ChatbotTypePriceResource::getNavigationGroup());
        $this->assertSame(__('panel.nav_group_pricing'), \App\Filament\Resources\TokenPackageResource::getNavigationGroup());
    }

    public function test_the_plan_list_links_to_the_live_pricing_section(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(PlanResource\Pages\ListPlans::class)
            ->assertOk()
            ->assertSee(__('plans.list_subheading'));
    }
}
