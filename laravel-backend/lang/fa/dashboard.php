<?php
// Admin + customer dashboard widgets (app/Filament/Widgets,
// app/Filament/Customer/Widgets) — separate domain from panel.php since
// these are dashboard-specific, not general panel chrome.
return [
    // Admin: stats row
    'admin_stats_active_tenants' => 'تننت‌های فعال',
    'admin_stats_active_tenants_change' => ':sign:count نسبت به ماه قبل',
    'admin_stats_revenue_this_month' => 'درآمد این ماه',
    'admin_stats_cost_this_month' => 'هزینه‌ی واقعی این ماه',
    'admin_stats_gross_margin' => 'حاشیه سود ناخالص',

    // Admin: charts
    'admin_chart_daily_messages' => 'تعداد پیام روزانه (۳۰ روز گذشته)',
    'admin_chart_daily_messages_label' => 'پیام‌ها',
    'admin_chart_revenue_vs_cost' => 'درآمد در برابر هزینه (۱۲ ماه گذشته)',
    'admin_chart_revenue_label' => 'درآمد',
    'admin_chart_cost_label' => 'هزینه',

    // Admin: operational tables
    'admin_table_tenants_at_risk' => 'تننت‌های در معرض ریزش یا فرصت فروش',
    'admin_table_tenants_at_risk_tenant_col' => 'تننت',
    'admin_table_tenants_at_risk_reason_col' => 'دلیل',
    'admin_table_tenants_at_risk_value_col' => 'وضعیت',
    'admin_table_tenants_at_risk_reason_quota' => 'نزدیک سقف سهمیه توکن',
    'admin_table_tenants_at_risk_reason_wallet' => 'موجودی کیف پول کم',
    'admin_table_tenants_at_risk_empty' => 'هیچ تننتی در معرض ریزش نیست.',
    'admin_table_failed_syncs' => 'آخرین sync‌های ناموفق',
    'admin_table_failed_syncs_tenant_col' => 'تننت',
    'admin_table_failed_syncs_type_col' => 'نوع',
    'admin_table_failed_syncs_error_col' => 'خطا',
    'admin_table_failed_syncs_when_col' => 'زمان',
    'admin_table_failed_syncs_empty' => 'هیچ sync ناموفقی ثبت نشده.',
    'admin_unanswered_rate' => 'نرخ سوال‌های بی‌پاسخ (کل پلتفرم)',
    'admin_unanswered_rate_desc' => 'سلامت محتوای همه‌ی چت‌بات‌ها — این ماه',

    // Customer: stats row
    'customer_stats_questions_month' => 'تعداد سوال این ماه',
    'customer_stats_tokens_remaining' => 'توکن باقی‌مانده',
    'customer_stats_tokens_unlimited' => 'نامحدود',
    'customer_stats_unanswered' => 'سوال بی‌جواب',
    'customer_stats_wallet_balance' => 'موجودی کیف پول',

    // Customer: content
    'customer_chart_daily_conversations' => 'مکالمه‌های روزانه (۳۰ روز گذشته)',
    'customer_chart_conversations_label' => 'مکالمه‌ها',
    'customer_top_topics' => 'پرتکرارترین موضوعات',
    'customer_top_topics_empty' => 'هنوز سوالی ثبت نشده.',
    'customer_recent_unanswered' => 'سوال‌های بی‌پاسخ اخیر',
    'customer_recent_unanswered_empty' => 'اخیراً سوال بی‌پاسخی ثبت نشده — عالیه!',
    'customer_recent_unanswered_view_all' => 'مشاهده‌ی همه در شکاف تقاضا',
    'customer_chatbot_status' => 'وضعیت چت‌بات‌ها',
    'customer_chatbot_status_active' => 'فعال',
    'customer_chatbot_status_syncing' => 'در حال sync',
    'customer_chatbot_status_error' => 'خطا در sync',
    'customer_chatbot_status_suspended' => 'معلق',
    'customer_chatbot_status_last_sync' => 'آخرین sync: :when',
    'customer_chatbot_status_never_synced' => 'هنوز sync نشده',

    // Onboarding checklist (empty state)
    'onboarding_title' => 'راه‌اندازی حساب شما',
    'onboarding_desc' => 'با تکمیل این چک‌لیست، داشبورد واقعی جای این کارت رو می‌گیره.',
    'onboarding_chatbot_created' => 'چت‌بات ساخته شد',
    'onboarding_chatbot_created_cta' => 'ساخت چت‌بات',
    'onboarding_plugin_installed' => 'افزونه‌ی وردپرس نصب و متصل شد',
    'onboarding_first_sync' => 'اولین sync محتوا انجام شد',
    'onboarding_first_conversation' => 'اولین مکالمه با یک بازدیدکننده ثبت شد',
];
