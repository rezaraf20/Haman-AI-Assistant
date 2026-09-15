<?php
return [
    'action' => 'Manual sync',
    'confirm_admin' => "This customer's content will be read from their site and indexed again. It costs embedding tokens and can take up to two minutes.",
    'confirm_customer' => 'Your products, pages and FAQs will be read and indexed again. This can take up to two minutes.',

    // success
    'done' => 'Sync finished',
    'done_body' => ':new new · :updated updated · :skipped unchanged · :deleted removed (in :seconds seconds)',
    'done_nothing' => 'Sync finished, but there was nothing to change — the site matches what is already indexed.',

    // failure — each one says exactly what is wrong
    'failed_title' => 'Sync did not run',
    'plugin_missing' => 'The Hamman plugin is not installed on :domain. No sync is possible until it is installed and connected.',
    'plugin_outdated' => 'The installed plugin is too old to support this. The customer needs to update to version :required or later.',
    'unreachable' => ':domain could not be reached. It may be down, or the domain may have changed.',
    'bad_secret' => 'The site rejected our signature. This customer\'s webhook secret does not match — regenerate it and ask them to paste the new value into the plugin.',
    'not_configured' => 'The plugin is installed but not configured yet — it has no API key or chatbot id.',
    'sync_unavailable' => 'The plugin is installed but its sync component is unavailable.',
    'rate_limited' => 'This site was synced recently. It can be synced again in :hours hour(s).',
    'no_domain' => 'No domain is recorded for this chatbot.',
    'no_secret' => 'This customer has no webhook secret.',
    'failed' => 'The site responded with HTTP :status.',

    // customer portal cap
    'daily_cap_reached' => 'You have run :cap manual syncs today. You can run another tomorrow.',
    'daily_cap_help' => ':cap per day — each sync re-indexes your site content and has a cost.',
];
