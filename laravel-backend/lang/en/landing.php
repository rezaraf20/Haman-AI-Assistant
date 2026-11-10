<?php

/*
 * Copy for the public site.
 *
 * Rule applied throughout: no numeric claims, no percentages, no named
 * model, no customer count, no certification the product does not hold.
 * WooCommerce is the only integration named, because it is the only one
 * that actually works.
 *
 * Every TODO(business) marks a commercial decision that is still open; the
 * wording there is a placeholder.
 */

return [
    'brand' => 'Haman AI',
    'tagline' => 'Chat. Agents. Sales. Growth.',

    'nav_features' => 'Features',
    'nav_pricing' => 'Pricing',
    'nav_how' => 'Setup',
    'nav_faq' => 'FAQ',
    'nav_login' => 'Sign in',
    'nav_cta' => 'Start free',
    'nav_lang_fa' => 'فارسی',
    'nav_lang_en' => 'English',

    // ── hero ─────────────────────────────────────────────────────────────
    'hero_title' => 'A shop assistant that knows [[your catalogue]]',
    'hero_subtitle' => 'Install one WooCommerce plugin. Your products, pages and FAQs sync across, and the assistant answers from those — not from guesswork.',
    'hero_cta' => 'Start free',
    'hero_cta_secondary' => 'See a demo',
    'hero_note' => 'Persian and English. Built for WooCommerce shops.',

    // Live-typing chat mockup beside the hero — a real conversation shape,
    // not a screenshot, so it stays crisp at any width and needs no image
    // to keep in sync with the product.
    'hero_demo_customer' => 'Do you have this water bottle in stock?',
    'hero_demo_bot_intro' => 'Yes — here it is:',
    'hero_demo_product_name' => 'Rabbit Travel Mug, 650ml',
    'hero_demo_product_price' => '165,000 Toman',
    'hero_demo_product_stock' => 'In stock',
    'hero_demo_add_to_cart' => 'Add to cart',
    'image_hero_alt' => 'The Haman AI assistant on a sample shop, suggesting two mugs with price and stock',

    // ── what it is ────────────────────────────────────────────────────────
    'about_eyebrow' => 'What is Haman AI?',
    'about_title' => 'A conversation, instead of [[a long search]]',
    'about_body1' => 'The customer asks for what they want in their own words. The assistant answers from that shop’s catalogue and pages, shows the product, and adds it to the cart right there when asked.',
    'about_body2' => 'Stock and price are read from your site at that moment, not from a copy that may have gone stale.',
    'panel_q' => 'Show me the recommended products, please.',
    'panel_a' => 'All three are in stock. Which should I add to the cart?',
    'p1' => 'Wireless headphones',
    'p1p' => '1,240,000',
    'p2' => 'Smart watch',
    'p2p' => '2,890,000',
    'p3' => 'Earbuds',
    'p3p' => '840,000',

    // ── six things it does ──────────────────────────────────────────────
    'features_eyebrow' => 'Features',
    'features_title' => 'What it [[actually]] does',
    'features_subtitle' => 'The list is exact because it only names what has been built.',

    'feature1_title' => 'Answers from your own content',
    'feature1_body' => 'Products, pages and FAQs are indexed, and answers are built from them. When something is not in your content the assistant says it does not know — it never guesses.',

    'feature2_title' => 'Live stock and price',
    'feature2_body' => 'Every answer about stock or price is read from your site at that moment, not from a cached copy that can go stale.',

    'feature3_title' => 'Comparison and recommendations',
    'feature3_body' => 'It can weigh two products against each other and suggest one, grounded in what is actually in your catalogue.',

    'feature4_title' => 'Cart and payment link',
    'feature4_body' => 'It can put a product in the cart and create a payment link when asked. Order status is shown only after the customer confirms with an SMS code.',

    'feature5_title' => 'Leads for what is out of stock',
    'feature5_body' => 'When a customer asks for something you do not have, it can capture their contact so you can follow up instead of losing the interest entirely.',

    'feature6_title' => 'Demand and trend reports',
    'feature6_body' => 'What customers asked for that you do not stock, which products get compared, and which topics are rising — drawn from real conversations on your shop.',

    // ── "see how it works" tabs ─────────────────────────────────────────
    'demo_title' => 'See how it works',
    'demo_head' => 'Three real shapes of [[conversation]]',
    'demo_sub' => 'And the report behind them.',

    'demo_tab_sales' => 'Sales conversation',
    'demo_tab_compare' => 'Product comparison',
    'demo_tab_gap' => 'Demand-gap report',

    'demo_sales_customer' => 'Do you carry a 12-cup French press?',
    'demo_sales_bot' => 'We do — the Steel French Press, 12 cups, currently 890,000 Toman and in stock. Want me to add it to your cart?',

    'demo_compare_customer' => 'What is the difference between the ceramic mug and the steel one?',
    'demo_compare_bot' => 'The ceramic mug keeps a drink hot for about half as long as the steel one, and costs less. The steel one is the one people usually add to the cart after asking.',

    'demo_gap_note' => 'Things customers asked for that are not in your catalogue, beside things that were asked about but did not sell.',
    'image_demo_alt' => 'The assistant comparing two flasks side by side: volume, heat retention, material and colours',

    // ── why ───────────────────────────────────────────────────────────────
    'why_eyebrow' => 'Why Haman AI?',
    'why_title' => 'A [[smarter]] way to talk to customers',
    'why1' => 'Turn finding a product detail from a long search into one plain question',
    'why2' => 'Cut the time spent answering the same questions over and over',
    'why3' => 'See what customers asked for that your catalogue did not have',
    'why4' => 'Switch each tool on or off separately, and see its cost before enabling it',
    'image_why_alt' => 'A sample shop with the Haman AI chat open and ready-made suggestions',

    // ── integrations ─────────────────────────────────────────────────────
    'how_eyebrow' => 'Getting started',
    'integrations_title' => 'Where it [[runs]]',
    'integrations_subtitle' => 'One platform today, done properly, rather than a dozen done halfway.',
    'integrations_wp' => 'WordPress',
    'integrations_woo' => 'WooCommerce',
    'integrations_step1_title' => 'Install the plugin',
    'integrations_step1_body' => 'Add the Haman AI plugin to your WordPress site from your panel.',
    'integrations_step2_title' => 'Paste your key',
    'integrations_step2_body' => 'Connect the plugin to your account with the key from your panel.',
    'integrations_step3_title' => 'Run the first sync',
    'integrations_step3_body' => 'Products, pages and FAQs sync across, and the assistant is live on your site.',

    // ── pricing ─────────────────────────────────────────────────────────
    'pricing_eyebrow' => 'Plans and pricing',
    'pricing_title' => 'Pricing',
    // TODO(business): trial policy is undecided — how long, and whether a
    // card is required up front.
    'pricing_subtitle' => 'No long-term contract. Change plan whenever you like.',
    'pricing_per_month' => 'per month',
    'pricing_cta' => 'Choose',
    'pricing_empty' => 'No plans have been published yet.',
    // Shown instead of the price grid when every paid plan still looks like
    // PlanSeeder's placeholder value (see Plan::PRICE_SANITY_THRESHOLD) —
    // an unedited "29" read as a real monthly price otherwise.
    'pricing_coming_soon' => 'Pricing is being finalized. Check back soon.',
    'pricing_popular' => 'Popular',
    // trans_choice: singular|plural. Persian has no plural, so lang/fa/landing.php
    // keeps these as one plain string each — trans_choice() returns a
    // pipe-less translation as-is regardless of $count.
    'pricing_chatbots' => ':count chatbot|:count chatbots',
    'pricing_tokens' => ':count token per month|:count tokens per month',
    'pricing_documents' => ':count document|:count documents',
    // TODO(business): enterprise wording, and whether its price is public or
    // "contact us".
    'pricing_free_line' => 'Free plan: :chatbots, :documents and :messages messages a month — at no cost.',
    'pricing_enterprise_note' => 'Talk to us about larger requirements.',
    'tool_compare_title' => 'Feature comparison',
    'tool_compare_feature' => 'Feature',
    'tool_compare_yes' => 'Included',
    'tool_compare_no' => 'Not included',

    // ── what a chatbot itself costs ─────────────────────────────────────
    'types_title' => 'What a chatbot costs',
    'types_subtitle' => 'A plan is the monthly subscription. This is the one-off price for the chatbot itself.',

    // ── form ────────────────────────────────────────────────────────────
    'signup_title' => 'Get started',
    'signup_subtitle' => 'Create an account, or sign in with your mobile number.',
    'signup_name' => 'Name',
    'signup_email' => 'Email',
    'signup_country' => 'Country',
    'signup_country_placeholder' => 'Select your country',
    // This is the one signup answer that decides the currency shown
    // everywhere in the portal afterward — see Tenant::currency().
    'signup_country_help' => 'This decides which currency you\'ll see in your account — Toman for Iran, Euro everywhere else.',
    'signup_password' => 'Password',
    'signup_password_confirm' => 'Confirm password',
    'signup_submit' => 'Create account',
    'signup_phone_alternative' => 'In Iran? Sign in with your mobile number',
    'signup_email_disabled' => 'Email signup is unavailable at the moment, because outbound email has not been configured on this installation yet. Please use mobile sign-in instead.',
    'signup_have_account' => 'Already have an account? Sign in',

    // ── FAQ ─────────────────────────────────────────────────────────────
    'faq_title' => 'Frequently asked questions',
    'faq_head' => 'What people usually [[ask]]',

    'faq_q1' => 'What does it run on?',
    'faq_a1' => 'WooCommerce on WordPress. You install the plugin and connect it to your account.',

    'faq_q2' => 'Where do the answers come from?',
    'faq_a2' => 'From your own site content: the products, pages and FAQs you have synced. Stock and price are read live from your site rather than from a stored copy.',

    'faq_q3' => 'What happens when it does not know?',
    'faq_a3' => 'It says so. The question is recorded as unanswered so you can see in the reports which content gap keeps repeating. It does not guess a price or a stock level.',

    'faq_q4' => 'Does it answer in Persian?',
    'faq_a4' => 'Yes — Persian and English, replying in the language the customer used.',

    'faq_q5' => 'Can you see my customers\' data?',
    'faq_a5' => 'Each shop\'s data lives in its own schema. If our support team opens a conversation to investigate a ticket they must choose a reason first, phone numbers and emails are hidden by default, and every access is written to an activity log.',

    'faq_q6' => 'Do I control what it is allowed to do?',
    'faq_a6' => 'Yes. Every tool — search, comparison, add to cart, payment link, order status — is switched on and off individually, and each one shows what it costs before you enable it.',

    'faq_q7' => 'How long does setup take?',
    // TODO(business): deliberately no number here. Do not put one in until
    // several real installations have been timed.
    'faq_a7' => 'Install the plugin, paste your key and run the first sync. A step-by-step guide is waiting in your panel once you have an account.',

    // ── final CTA band ──────────────────────────────────────────────────
    'final_cta_title' => 'Give it [[your catalogue]]',
    'final_cta_subtitle' => 'It only answers from what you give it — install the plugin and see what it says.',
    'final_cta_button' => 'Start free',
    'cta_plans' => 'See plans and pricing',

    // ── footer ──────────────────────────────────────────────────────────
    'footer_tagline' => 'A shop assistant, grounded in your own content.',
    'footer_login' => 'Sign in',
    'footer_rights' => 'All rights reserved.',
];
