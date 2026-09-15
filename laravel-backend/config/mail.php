<?php

/*
 * Mail configuration.
 *
 * The values here are only the starting point. The live SMTP credentials come
 * from the admin settings page, not from env — MailSettingsProvider overwrites
 * the smtp mailer at boot from PlatformSetting.
 *
 * Reading the database inside this file directly would look tidier and break
 * two things: `config:cache` runs at build time with no database, and artisan
 * has to work before the migration that creates the table. So env stays as the
 * fallback, and the provider layers settings on top once the app is up.
 */
return [

    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [

        'smtp' => [
            'transport'  => 'smtp',
            'scheme'     => env('MAIL_SCHEME'),
            'url'        => env('MAIL_URL'),
            'host'       => env('MAIL_HOST', '127.0.0.1'),
            'port'       => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username'   => env('MAIL_USERNAME'),
            'password'   => env('MAIL_PASSWORD'),
            'timeout'    => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'log' => [
            'transport' => 'log',
            'channel'   => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers'   => ['smtp', 'log'],
        ],

    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name'    => env('MAIL_FROM_NAME', 'Haman AI'),
    ],

];
