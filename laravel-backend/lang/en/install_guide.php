<?php
return [
    'nav' => 'Setup guide',
    'title' => 'Getting set up',
    'progress' => ':done of :total steps done',
    'next_up' => 'Next: :step',
    'all_done' => 'Every step is done.',

    // ── step 1 ──────────────────────────────────────────────────────────
    'step_chatbot_title' => 'Create a chatbot',
    'step_chatbot_intro' => 'Each chatbot is tied to one domain.',
    'step_chatbot_body' => 'Create a chatbot first. The id you get is what you paste into the plugin.',
    'step_chatbot_cta' => 'Create a chatbot',

    // ── step 2 ──────────────────────────────────────────────────────────
    'step_download_title' => 'Download and install the plugin',
    'step_download_intro' => 'The WordPress plugin is installed on your own site.',
    'step_download_body' => 'Download the file below and install it like any other plugin:',
    'step_download_1' => 'In WordPress, go to Plugins → Add New → Upload Plugin.',
    'step_download_2' => 'Choose the zip file and select Install Now.',
    'step_download_3' => 'Once installed, select Activate.',
    'step_download_cta' => 'Download the plugin',

    // ── upgrading from 1.x ──────────────────────────
    'upgrade_title' => 'Already have version 1.x installed?',
    'upgrade_intro' => 'Version 2.0.0 renames the plugin folder, so WordPress treats it as a separate plugin. Install it the way you would a new one.',
    'upgrade_keeps_settings' => 'Your settings are kept. On activation the new version reads your API key, chatbot ID, secret and every other setting from the old plugin and carries them across, so you do not have to enter anything again.',
    'upgrade_1' => 'Download version 2.0.0 below.',
    'upgrade_2' => 'In WordPress go to Plugins, then deactivate and delete "Hamman AI Chatbot".',
    'upgrade_3' => 'Do NOT tick any "delete data" option while removing it.',
    'upgrade_4' => 'Install the new zip and activate it. Your settings will already be filled in.',
    'upgrade_verify' => 'To check: open Haman → Settings → Connection. Your chatbot ID and API key should still be there. Then press Test connection.',
    'upgrade_why' => 'Why the rename: our brand is Haman, with one "m". The plugin was published as "Hamman" by mistake and this corrects it.',

    // ── step 3 ──────────────────────────────────────────────────────────
    'step_connect_title' => 'Paste your key and test the connection',
    'step_connect_intro' => 'This turns green only once the plugin has genuinely reached your account.',
    'step_connect_body' => 'In WordPress go to Haman → Settings → Connection and enter these three values:',
    'field_api_url' => 'API URL',
    'field_chatbot_id' => 'Chatbot ID',
    'field_api_key' => 'API key',
    'field_none_yet' => 'not created yet',
    'key_used' => 'last used :when',
    'key_never_used' => 'never used — so the plugin has not connected yet',
    'key_shown_once' => 'The full key is shown once, when it is created. If you do not have it, create a new one.',
    'step_connect_cta' => 'Manage API keys',
    'step_connect_test' => 'Then press Test connection. If it succeeds, this step turns green on this page.',

    // ── step 4 ──────────────────────────────────────────────────────────
    'step_sync_title' => 'Run the first sync',
    'step_sync_intro' => 'Until content is synced the assistant has nothing to answer from.',
    'step_sync_body' => 'Press Run Full Sync in the plugin, or run a manual sync from this panel. Your products, pages and FAQs are read and indexed.',
    'step_sync_cta' => 'My chatbots',

    // ── step 5 ──────────────────────────────────────────────────────────
    'step_tools_title' => 'Switch on the tools',
    'step_tools_intro' => 'A tool that is off is not offered to the assistant at all.',
    'step_tools_body' => 'Open Chatbot tools in each chatbot\'s settings and enable the ones you want: search, comparison, live stock, add to cart, payment link and order status.',
    'step_tools_cost' => 'Each tool shows what it costs — some make a live request to your site, and some send an SMS billed to your wallet.',
    'step_tools_cta' => 'Chatbot settings',

    // ── troubleshooting ─────────────────────────────────────────────────
    'trouble_title' => 'Troubleshooting',
    'trouble_intro' => 'Problems we have actually seen on real installations.',
    'trouble_fix' => 'Fix:',

    'trouble_404_symptom' => 'The connection test or a sync returns 404, or the wp-json address does not open.',
    'trouble_404_cause' => 'The WordPress REST API is not reachable. Usually because permalinks are still set to Plain, which means the wp-json route is never created.',
    'trouble_404_fix' => 'In WordPress go to Settings → Permalinks, choose anything other than Plain, and save. Then test again.',

    'trouble_firewall_symptom' => 'The connection times out or returns 403, even though the site opens fine in a browser.',
    'trouble_firewall_cause' => 'A firewall or security plugin is blocking REST requests from outside. Some hosts do this by default.',
    'trouble_firewall_fix' => 'Ask your host or security plugin to allow /wp-json/haman/. With Wordfence or similar, add an exception for REST requests to that path.',

    'trouble_outdated_symptom' => 'You see a "plugin is out of date" message, or a feature described here is missing from the plugin.',
    'trouble_outdated_cause' => 'The installed version is older than the one that has the feature.',
    'trouble_outdated_fix' => 'Download the current version from this page, deactivate and delete the old plugin, then install the new one. Your settings are preserved.',

    'trouble_nowoo_symptom' => 'You get "WooCommerce not active".',
    'trouble_nowoo_cause' => 'The Haman plugin is installed but WooCommerce is not active on that site.',
    'trouble_nowoo_fix' => 'Install and activate WooCommerce. Without it, products and live stock will not work; pages and FAQs still sync.',

    'trouble_secret_symptom' => '"Invalid signature", or a 403 during sync.',
    'trouble_secret_cause' => 'The secret on the site does not match the one recorded here — usually after it was regenerated and the new value was never pasted back.',
    'trouble_secret_fix' => 'Open a ticket so we can regenerate it, then paste the new value into the plugin settings. We never see the old or new value.',
];
