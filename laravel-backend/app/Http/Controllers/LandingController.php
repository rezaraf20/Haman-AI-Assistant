<?php
namespace App\Http\Controllers;

use App\Models\Plan;
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
    public function index(Request $request)
    {
        return view('landing.index', [
            'plans'        => $this->publicPlans(),
            'currency'     => (string) Settings::get('pricing.default_currency'),
            'emailSignup'  => MailSettings::isUsable(),
            'faq'          => $this->faq(),
        ]);
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
