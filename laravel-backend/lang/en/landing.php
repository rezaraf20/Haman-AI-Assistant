<?php

/*
 * Draft copy for the public site.
 *
 * Rule applied throughout: no numeric claims — no multipliers, no
 * percentages, and no named integration the product does not support.
 * WooCommerce is the only one mentioned, because it is the only one that
 * actually works.
 *
 * Every TODO(business) marks a commercial decision that is still open; the
 * wording there is a placeholder.
 */

return [
    'brand' => 'Hamman',
    'nav_features' => 'Features',
    'nav_reports' => 'Reports',
    'nav_pricing' => 'Pricing',
    'nav_faq' => 'FAQ',
    'nav_login' => 'Sign in',
    'nav_cta' => 'Get started',

    // ── headline and value proposition ──────────────────────────────────
    'hero_title' => 'A shop assistant that knows your catalogue',
    'hero_subtitle' => 'Install one WooCommerce plugin. Your products, pages and FAQs sync across, and the assistant answers from those — not from guesswork.',
    'hero_cta' => 'Start free',
    'hero_cta_secondary' => 'Request a demo',
    'hero_note' => 'Persian and English. Built for WooCommerce shops.',

    // ── the three things that actually work ─────────────────────────────
    'features_title' => 'Three things it does',
    'features_subtitle' => 'The list is short because it only names what has been built.',

    'feature1_title' => 'Answers from your own content',
    'feature1_body' => 'Your products, pages and FAQs are indexed, and answers are built from them. When something is not in your content the assistant says it does not know, and that question is recorded for you as unanswered.',

    'feature2_title' => 'Acts on the cart and on payment',
    'feature2_body' => 'It reads stock and price from your site at that moment, puts a product in the cart, and creates a payment link when asked. Order status is shown only after the customer confirms with an SMS code.',

    'feature3_title' => 'Demand reports',
    'feature3_body' => 'What customers asked for that you do not stock, which products get compared against each other, and which questions went unanswered — drawn from real conversations on your shop.',

    // ── report showcase ─────────────────────────────────────────────────
    'reports_title' => 'What you get after the first sync',
    'reports_subtitle' => 'Everyone has seen a chat window. This is the part that makes the difference: what your customers wanted and you did not have.',

    'report_trends_title' => 'Trends',
    'report_trends_body' => 'The topics that keep coming up, over thirty days to a year, and whether each one is rising or falling against the period before.',

    'report_gap_title' => 'Demand gap',
    'report_gap_body' => 'Things customers asked for that are not in your catalogue, beside things that were asked about but did not sell.',

    'report_compared_title' => 'Most compared',
    'report_compared_body' => 'Which two products get weighed against each other, and which of them more often reaches the cart afterwards.',

    'report_unanswered_title' => 'Unanswered questions',
    'report_unanswered_body' => 'Every time the assistant could not answer, with similar questions grouped together so you can see which content gap keeps repeating.',

    'report_demo_label' => 'Illustration',
    'report_demo_note' => 'The figures shown here are sample data and only illustrate the shape of the report.',

    // ── pricing ─────────────────────────────────────────────────────────
    'pricing_title' => 'Pricing',
    // TODO(business): trial policy is undecided — how long, and whether a
    // card is required up front.
    'pricing_subtitle' => 'No long-term contract. Change plan whenever you like.',
    'pricing_per_month' => 'per month',
    'pricing_cta' => 'Choose',
    'pricing_empty' => 'No plans have been published yet.',
    'pricing_chatbots' => ':count chatbots',
    'pricing_tokens' => ':count tokens per month',
    'pricing_documents' => ':count documents',
    'pricing_domains' => ':count domains',
    // TODO(business): enterprise wording, and whether its price is public or
    // "contact us".
    'pricing_enterprise_note' => 'Talk to us about larger requirements.',

    // ── form ────────────────────────────────────────────────────────────
    'signup_title' => 'Get started',
    'signup_subtitle' => 'Create an account, or leave a message to see a demo.',
    'signup_email_tab' => 'Create account',
    'signup_demo_tab' => 'Request a demo',
    'signup_name' => 'Name',
    'signup_email' => 'Email',
    'signup_password' => 'Password',
    'signup_password_confirm' => 'Confirm password',
    'signup_submit' => 'Create account',
    'signup_phone_alternative' => 'In Iran? Sign in with your mobile number',
    'signup_email_disabled' => 'Email signup is unavailable at the moment, because outbound email has not been configured on this installation yet. Please use mobile sign-in instead.',
    'signup_have_account' => 'Already have an account? Sign in',

    'demo_message' => 'What would you like to see?',
    'demo_submit' => 'Send request',
    'demo_sent' => 'Thanks — we have your request and will be in touch.',
    // TODO(business): a demo request currently opens a ticket. If it should
    // reach a sales inbox or a CRM instead, say where.

    // ── FAQ ─────────────────────────────────────────────────────────────
    'faq_title' => 'Frequently asked questions',

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

    // ── footer ──────────────────────────────────────────────────────────
    'footer_tagline' => 'A shop assistant, grounded in your own content.',
    // TODO(business): company address, phone and legal identifier go here.
    'footer_contact' => 'Contact',
    'footer_login' => 'Sign in',
    'footer_rights' => 'All rights reserved.',
];
