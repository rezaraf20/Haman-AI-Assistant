<?php
namespace App\Http\Controllers;

use App\Models\{ChatbotTypePrice, Plan};
use App\Support\{LandingContent, MailSettings, Money, Settings};
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public site. Until now there was nowhere to send someone who became
 * interested — the only public pages were a login form and a payment
 * callback.
 *
 * Every number on the page comes from the database or the settings table.
 * Nothing here hardcodes a price, and nothing here makes a claim the product
 * cannot back: no percentages, no multipliers, no named integrations beyond
 * WooCommerce, which is the one this actually supports.
 */
class LandingController extends Controller
{
    /**
     * Cached briefly at the edge and in the browser.
     *
     * The page is the same for every visitor within a locale, and its only
     * moving parts are prices an admin edits. A minute is short enough that
     * a price change shows up while someone is still looking at the panel,
     * and long enough that the page is not rebuilt per visitor.
     */
    private const CACHE_SECONDS = 60;

    public function index(Request $request)
    {
        $plans = $this->publicPlans();
        // fa reads the page in Toman, every other locale in Euro — a
        // language choice, not the admin-wide pricing.default_currency
        // setting (that one still routes payment gateways; see
        // PaymentGatewayManager). See Money::currencyForLocale().
        $currency = Money::currencyForLocale();

        $response = response()->view('landing.index', [
            'plans'               => $plans,
            'currency'            => $currency,
            'pricingComingSoon'   => $this->pricingLooksUnconfigured($plans, $currency),
            'popularSlug'         => (string) Settings::get('pricing.popular_plan_slug'),
            'chatbotTypes'        => $this->chatbotTypePrices(),
            'emailSignup'         => MailSettings::isUsable(),
            'faq'                 => $this->faq(),
            'contact'             => LandingContent::contact(),
            'countries'           => \App\Support\Countries::all(),
        ]);

        // Vary on the language header: the same URL serves Persian and
        // English, and a shared cache must not hand one to the other.
        return $response
            ->header('Cache-Control', 'public, max-age=' . self::CACHE_SECONDS)
            ->header('Vary', 'Accept-Language');
    }

    /**
     * What a chatbot itself costs, as the portal charges for it.
     *
     * Separate from the plans above and read from the same table the panel
     * edits, so the two cannot disagree. A plan is the monthly subscription;
     * this is the one-off price per chatbot type, and a visitor comparing
     * the site against a quote needs to see both.
     */
    private function chatbotTypePrices()
    {
        return ChatbotTypePrice::active()->orderBy('price_toman')->get();
    }

    /**
     * Only plans an admin has deliberately published — see the is_public
     * column. The free plan every signup lands on is active without being
     * something to advertise, so "active" is the wrong question here.
     */
    private function publicPlans()
    {
        return Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * True when this locale's currency can't actually be shown yet — either
     * nobody has set an FX rate for it (Money::convert() returns null for
     * every plan, not just the cheap ones), or every paid, published plan
     * still looks like PlanSeeder's placeholder (see Plan::
     * looksLikeDefaultPrice()) — the site would otherwise advertise "29
     * Toman" as a real monthly price. That second check is always in raw
     * Toman terms regardless of $currency: whether 99 Toman looks like
     * unedited seed data doesn't depend on what it's being displayed as. A
     * plan deliberately priced at 0 (Free) doesn't count either way — a
     * free-only public lineup is a real, intentional choice, not
     * unconfigured pricing.
     */
    private function pricingLooksUnconfigured($plans, string $currency): bool
    {
        if (Money::convert(1_000_000, $currency) === null) return true;

        $paidPlans = $plans->where('price_monthly', '>', 0);

        return $paidPlans->isNotEmpty()
            && $paidPlans->every(fn (Plan $plan) => $plan->price_monthly < Plan::PRICE_SANITY_THRESHOLD);
    }

    /**
     * About/contact/terms/privacy — one view for all four, distinguished
     * only by which slug's content LandingContent hands back. The route
     * itself (routes/web.php) restricts {slug} to LandingContent::LEGAL_PAGES,
     * so an unknown slug never reaches here at all; this check stays as the
     * hard backstop in case that route constraint is ever loosened.
     */
    public function legal(string $slug)
    {
        if (!in_array($slug, LandingContent::LEGAL_PAGES, true)) {
            throw new NotFoundHttpException();
        }

        return response()
            ->view('landing.legal', [
                'slug'    => $slug,
                'title'   => LandingContent::legalTitle($slug, app()->getLocale()),
                'body'    => LandingContent::legalBody($slug, app()->getLocale()),
                'contact' => LandingContent::contact(),
            ])
            ->header('Cache-Control', 'public, max-age=' . self::CACHE_SECONDS)
            ->header('Vary', 'Accept-Language');
    }

    /**
     * Editable in the panel as a re-orderable list (LandingContent::faq()),
     * so unlike every other piece of copy on this page its item count is
     * not fixed at seven — that is exactly why this reads LandingContent
     * directly instead of going through __('landing.faq_qN'), the trick
     * every OTHER field on this page uses (see LandingContent's own
     * docblock): a numbered translation key cannot represent "how many".
     *
     * @return array<int, array{q:string, a:string}>
     */
    private function faq(): array
    {
        $locale = app()->getLocale();

        return collect(LandingContent::faq())
            ->map(fn ($item) => [
                'q' => $item["q_{$locale}"] ?? $item['q_fa'],
                'a' => $item["a_{$locale}"] ?? $item['a_fa'],
            ])
            ->all();
    }
}
