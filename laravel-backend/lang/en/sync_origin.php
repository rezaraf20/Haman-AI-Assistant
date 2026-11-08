<?php

return [
    'refused' => 'This request came from :origin, but this chatbot’s registered domain is :registered — no document was archived or deleted and the chatbot’s sync settings were not changed. If this site owns the chatbot, register its domain in your Haman panel; if not, create a separate chatbot for it.',
    'unknown_site' => 'an unidentified site',
    'no_domain' => '(none registered)',
    'portal_title' => 'Requests refused from other sites',
    'portal_body' => 'Sites that are not this chatbot’s registered domain asked to archive or delete documents, or to change the sync settings. None of it was done. Adding and updating content from those sites still works.',
    'portal_row' => ':origin — :action — :count times, last :when',
    'portal_registered' => 'Registered domain: :domain',
    'action_settings' => 'change sync settings',
    'action_clear' => 'delete all documents',
    'action_delete' => 'archive a document',
];
