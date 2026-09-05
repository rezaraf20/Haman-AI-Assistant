<?php
return [
    // Admin: stats row
    'admin_stats_active_tenants' => 'Active Tenants',
    'admin_stats_active_tenants_change' => ':sign:count vs. last month',
    'admin_stats_revenue_this_month' => 'Revenue This Month',
    'admin_stats_cost_this_month' => 'Actual Cost This Month',
    'admin_stats_gross_margin' => 'Gross Margin',

    // Admin: charts
    'admin_chart_daily_messages' => 'Daily Messages (Last 30 Days)',
    'admin_chart_daily_messages_label' => 'Messages',
    'admin_chart_revenue_vs_cost' => 'Revenue vs. Cost (Last 12 Months)',
    'admin_chart_revenue_label' => 'Revenue',
    'admin_chart_cost_label' => 'Cost',

    // Admin: operational tables
    'admin_table_tenants_at_risk' => 'Tenants at Risk of Churn or a Sales Opportunity',
    'admin_table_tenants_at_risk_tenant_col' => 'Tenant',
    'admin_table_tenants_at_risk_reason_col' => 'Reason',
    'admin_table_tenants_at_risk_value_col' => 'Status',
    'admin_table_tenants_at_risk_reason_quota' => 'Near token quota limit',
    'admin_table_tenants_at_risk_reason_wallet' => 'Low wallet balance',
    'admin_table_tenants_at_risk_empty' => 'No tenants currently at risk.',
    'admin_table_failed_syncs' => 'Most Recent Failed Syncs',
    'admin_table_failed_syncs_tenant_col' => 'Tenant',
    'admin_table_failed_syncs_type_col' => 'Type',
    'admin_table_failed_syncs_error_col' => 'Error',
    'admin_table_failed_syncs_when_col' => 'When',
    'admin_table_failed_syncs_empty' => 'No failed syncs recorded.',
    'admin_unanswered_rate' => 'Unanswered Question Rate (Whole Platform)',
    'admin_unanswered_rate_desc' => 'Content health across every chatbot — this month',

    // Customer: stats row
    'customer_stats_questions_month' => 'Questions This Month',
    'customer_stats_tokens_remaining' => 'Tokens Remaining',
    'customer_stats_tokens_unlimited' => 'Unlimited',
    'customer_stats_unanswered' => 'Unanswered Questions',
    'customer_stats_wallet_balance' => 'Wallet Balance',

    // Customer: content
    'customer_chart_daily_conversations' => 'Daily Conversations (Last 30 Days)',
    'customer_chart_conversations_label' => 'Conversations',
    'customer_top_topics' => 'Most Frequent Topics',
    'customer_top_topics_empty' => 'No questions recorded yet.',
    'customer_recent_unanswered' => 'Recent Unanswered Questions',
    'customer_recent_unanswered_empty' => 'No unanswered questions recently — great!',
    'customer_recent_unanswered_view_all' => 'View all in Demand Gap',
    'customer_chatbot_status' => 'Chatbot Status',
    'customer_chatbot_status_active' => 'Active',
    'customer_chatbot_status_syncing' => 'Syncing',
    'customer_chatbot_status_error' => 'Sync Error',
    'customer_chatbot_status_suspended' => 'Suspended',
    'customer_chatbot_status_last_sync' => 'Last sync: :when',
    'customer_chatbot_status_never_synced' => 'Never synced',

    // Onboarding checklist (empty state)
    'onboarding_title' => 'Set Up Your Account',
    'onboarding_desc' => 'Complete this checklist and your real dashboard will take this card\'s place.',
    'onboarding_chatbot_created' => 'Chatbot created',
    'onboarding_chatbot_created_cta' => 'Create a Chatbot',
    'onboarding_plugin_installed' => 'WordPress plugin installed and connected',
    'onboarding_first_sync' => 'First content sync completed',
    'onboarding_first_conversation' => 'First conversation with a visitor recorded',
];
