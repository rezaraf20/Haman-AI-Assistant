<?php

return [
    'awaiting_domain' => 'This chatbot is not connected to any domain yet and does not answer. The site owner needs to register their site’s domain in the Haman panel (My chatbots → Register domain).',
    'domain_not_registered' => 'This chatbot is not registered for this site. Each chatbot works only on the domain registered for it; create a separate chatbot for this site in the Haman panel.',
    'api_key_other_chatbot' => 'This API key belongs to a different chatbot. Each key works for exactly one chatbot — copy this chatbot’s own key from the Haman panel.',
    'api_key_unbound' => 'This API key is not bound to any chatbot and is no longer accepted. Copy the key for this site’s chatbot from the Haman panel (API keys).',
    'multi_domain_unsupported' => 'Each chatbot has exactly one domain. It is registered in the Haman panel and changed through support; create a separate chatbot for another site.',
    'awaiting_label' => 'Awaiting connection — register the site’s domain',
    'register_action' => 'Register domain',
    'register_heading' => 'Register the site’s domain',
    'register_help' => 'Just the domain name, like shop.com. This chatbot will work only on this domain; changing it later goes through support.',
    'register_done' => 'Domain registered — the chatbot is active.',
    'register_done_inactive' => 'Domain registered. The chatbot is still inactive for another reason.',
    'invalid' => 'That is not a valid domain. Enter just the domain name, like shop.com (no http, path or IP).',
    'already_set' => 'This chatbot already has a domain. Contact support to change it.',
    'rejections_label' => 'Requests from other sites',
    'rejections_row' => ':origin (:count times)',
];
