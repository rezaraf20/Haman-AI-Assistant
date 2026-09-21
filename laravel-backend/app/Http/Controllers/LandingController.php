<?php
namespace App\Http\Controllers;

use App\Models\{ChatbotTypePrice, Plan};
use App\Support\{MailSettings, Settings};
use Illuminate\Http\Request;

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
        $response = response()->view('landing.index', [
            'plans'        => $this->publicPlans(),
            'popularSlug'  => (string) Settings::get('pricing.popular_plan_slug'),
            'chatbotTypes' => $this->chatbotTypePrices(),
            'currency'     => (string) Settings::get('pricing.default_currency'),
            'emailSignup'  => MailSettings::isUsable(),
            'faq'          => $this->faq(),
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
     * @return array<int, array{q:string, a:string}>
     */
    private function faq(): array
    {
        return collect(range(1, 7))
            ->map(fn ($i) => ['q' => __("landing.faq_q{$i}"), 'a' => __("landing.faq_a{$i}")])
            ->all();
    }
}
