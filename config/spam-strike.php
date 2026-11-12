<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The form token
    |--------------------------------------------------------------------------
    |
    | A protected form only goes through with a token the package's script
    | fetches from the server on the first touch of the form. A bot that posts
    | without running JavaScript has none. The token holds the moment it was
    | handed out, encrypted with the application key, so it also tells how long
    | the form was in use.
    |
    | The page itself carries nothing per visitor, so it can stay in a full-page
    | or CDN cache. Only the token route is never cached.
    |
    */

    'route' => [
        'prefix' => 'spam-strike',
        'middleware' => ['web'],
        'throttle' => '20,1',
    ],

    'fields' => [
        'token' => '_strike_token',
        'honeypot' => 'website_url',
        'email' => 'email',
    ],

    // Fewer seconds between the token and the submit is a bot. The script waits
    // this long by itself when someone (or an AI agent) is quicker.
    'minimum_seconds' => 3,

    // A token older than this is refused, but not reported: that is a person who
    // left the page open.
    'token_lifetime' => 86400,

    /*
    |--------------------------------------------------------------------------
    | What a blocked submission gets back
    |--------------------------------------------------------------------------
    */

    'response' => [
        'status' => 422,
        'message' => 'This form could not be sent. Please enable JavaScript and try again.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fooling the bot
    |--------------------------------------------------------------------------
    |
    | For the reasons listed here a blocked submission gets the answer of a
    | form that went through, so the bot does not learn it was caught and does
    | not try harder. It is still refused, and still reported where it would be.
    | The reasons: 'no javascript', 'honeypot', 'too fast', 'expired token'.
    |
    | Leave 'no javascript' and 'expired token' out unless you mean it: a real
    | person with JavaScript blocked, or a tab left open for a day, would then
    | think their message was sent.
    |
    */

    'silent' => [
        'reasons' => [],

        // What a request that expects JSON gets back, with status 200.
        'json' => ['success' => true],

        // What a plain form gets flashed into the session as it is sent back.
        'flash' => ['success' => 'Thank you for your message.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | The notice without JavaScript
    |--------------------------------------------------------------------------
    |
    | <x-spam-strike::fields /> hides the form in <noscript> and loads a notice
    | in a small iframe, in the visitor's first browser language. A language that
    | is not listed gets nothing at all: no notice and no form. Set a language to
    | null to show nothing for it either.
    |
    */

    'noscript' => [
        'height' => '10rem',
        'messages' => [
            'nl' => 'Het contactformulier kon niet geladen worden. Schakel JavaScript in of neem contact op via :email.',
            'en' => 'The contact form could not be loaded. Please enable JavaScript or get in touch at :email.',
        ],
        'email' => env('SPAM_STRIKE_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Abuse reports
    |--------------------------------------------------------------------------
    |
    | A submission without a valid token (also one that filled the honeypot) is
    | reported to the abuse contact of the sender's domain, which RDAP (what
    | WHOIS became) gives, with abuse@domain when it has none. It runs as a
    | queued job; the request only gets its quick refusal.
    |
    | The address in a web form is whatever the sender typed, so the limits are
    | not optional: one report per domain per period, and a daily maximum, so a
    | bot filling in other people's domains cannot make your site send mail.
    |
    */

    'reports' => [
        'enabled' => env('SPAM_STRIKE_REPORTS', true),

        'queue' => env('SPAM_STRIKE_QUEUE'),

        'from' => [
            'address' => env('SPAM_STRIKE_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
            'name' => env('SPAM_STRIKE_FROM_NAME', env('MAIL_FROM_NAME')),
        ],

        // You, in copy of every report. Also who hears about registrars that only
        // take reports through a web form.
        'cc' => array_filter(explode(',', (string) env('SPAM_STRIKE_CC', ''))),

        // Send the sender's own address a blind copy, so they know. Skipped when the
        // domain has no MX record: the copy would only bounce back to you.
        'bcc_sender' => env('SPAM_STRIKE_BCC_SENDER', true),

        // Also report to the network the request came from: the hosting provider
        // the bot runs at. It needs the visitor's real IP address, so trust your
        // proxies (TrustProxies) when the application sits behind a CDN.
        'network' => env('SPAM_STRIKE_REPORT_NETWORK', true),

        // How long one domain, or one IP address, waits for its next report.
        'per_domain_hours' => 24,

        'daily_limit' => 25,

        // The registrar of a free mailbox provider has nothing to do with one of
        // its users, and your own domain is never reported.
        'exempt_domains' => [
            'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.nl', 'outlook.com', 'live.com', 'live.nl',
            'msn.com', 'yahoo.com', 'yahoo.nl', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'protonmail.com',
            'proton.me', 'mail.com', 'zoho.com', 'yandex.com', 'gmx.com', 'gmx.nl', 'gmx.de', 'web.de',
            't-online.de', 'qq.com', '163.com', '126.com',
        ],

        // Abuse desks that answer mail with "use our web form" still get the mail
        // (and the sender the blind copy) unless you list them here. A listed
        // desk gets no mail; the cc addresses get the report and the link, e.g.
        // 'registrar-abuse@cloudflare.com' => 'https://www.cloudflare.com/trust-hub/reporting-abuse/'.
        'web_form_only' => [],

        // The abuse desk of whoever runs a domain's nameservers, for when RDAP gives
        // no abuse contact: the domain sits behind that party, and abuse@domain
        // would only reach the spammer. Keyed by nameserver suffix; the first
        // address gets the report, the others are in cc.
        'nameserver_fallbacks' => [
            'ns.cloudflare.com' => ['abuse@cloudflare.com', 'abusereply@cloudflare.com'],
        ],

        // Domains that are you. A form with an address there (you testing your own
        // form, say) is never reported anywhere: not to the registrar and not to
        // the network it came from. The domain of app.url and of the from address
        // count already.
        'own_domains' => [],

        // Addresses and ranges whose network is never reported: your office, your
        // home connection, a monitoring service. An address that sent a valid form
        // in the last 30 days is never reported either.
        'ignore_ips' => array_filter(explode(',', (string) env('SPAM_STRIKE_IGNORE_IPS', ''))),

        // Addresses that only describe your own network, never the sender's.
        'hidden_networks' => ['100.64.0.0/10'],
    ],

];
