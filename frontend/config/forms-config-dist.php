<?php
return [
    'bs' => [
        'db' => true,
        'form'  => 'bs.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v2',
        'notify_email' => 'sekretariat@example.com',
        'prefill_fields' => [
            'unternehmen',
            'ansprechpartner',
            'kontakt_email'
        ],
    ],
    'bk' => [
        'db' => true,
        'form'  => 'bk.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v2',
        'notify_email' => '',
    ],
    'ausbildernachmittag' => [
        'db' => false,
        'form'  => 'ausbildernachmittag.json',
        'theme' => 'survey_theme.json',
        'version' => '2026-01-v2',
        'notify_email' => 'ausbildernachmittag@example.com',
    ],
];
?>