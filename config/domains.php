<?php

/*
|--------------------------------------------------------------------------
| Custom domains
|--------------------------------------------------------------------------
|
| A workspace proves it owns a domain with a TXT record, then points the
| domain at this server. Certificates are issued by the web server (for
| example Caddy's on-demand TLS, asking GET /domains/tls-check?domain=…).
|
*/

return [

    // Hostname customers point their CNAME at, e.g. "domains.yourapp.com".
    'cname_target' => env('DOMAINS_CNAME_TARGET') ?: parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),

    // Optional public IPv4 address for apex domains that cannot use a CNAME.
    'a_record' => env('DOMAINS_A_RECORD') ?: null,

    // The proxied hostname Cloudflare forwards customer traffic to (its fallback origin).
    'origin_hostname' => env('CLOUDFLARE_ORIGIN_HOSTNAME') ?: null,

    // TXT record name prefix: _tour-verify.tours.agency.com
    'txt_prefix' => '_tour-verify',

    // Hosts that always serve the full application (besides APP_URL's host).
    'app_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOMAINS_APP_HOSTS', 'localhost,127.0.0.1'))))),

    // Seconds a domain lookup is cached for incoming requests.
    'cache_seconds' => 300,

];
