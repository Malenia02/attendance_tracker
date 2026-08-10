<?php

return [
    /*
    | The external Vercel proxy signs the original client IP before forwarding
    | an API request to Render. Production rejects unsigned API requests when
    | FRONTEND_API_PROXY is enabled, which prevents direct-origin IP spoofing.
    */
    'frontend_proxy_signing_secret' => env('FRONTEND_PROXY_SIGNING_SECRET'),
    'frontend_proxy_signature_ttl_seconds' => (int) env(
        'FRONTEND_PROXY_SIGNATURE_TTL_SECONDS',
        90
    ),

    /* Office-network registrations are intentionally short-lived because
       most consumer and small-office internet plans use dynamic public IPs. */
    'office_network_validity_days' => (int) env('OFFICE_NETWORK_VALIDITY_DAYS', 30),
    'allow_private_office_network_ips' => (bool) env(
        'ALLOW_PRIVATE_OFFICE_NETWORK_IPS',
        false
    ),
];
