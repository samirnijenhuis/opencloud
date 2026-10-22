<?php

return [

    'version' => env('OPENCLOUD_VERSION', 'dev'),

    /*
     * The installer prints this token in the setup URL. When it is empty, nobody can create the owner.
     */
    'setup_token' => env('OPENCLOUD_SETUP_TOKEN'),

    /*
     * "acme" uses Let's Encrypt. "internal" uses the local Caddy CA, for tests.
     */
    'tls_issuer' => env('OPENCLOUD_TLS_ISSUER', 'acme'),

    'registry_host' => 'registry.opencloud.svc.cluster.local:5000',

    'buildkit_addr' => 'tcp://buildkitd.opencloud.svc.cluster.local:1234',

    'data_path' => env('OPENCLOUD_DATA_PATH', storage_path('opencloud')),

];
