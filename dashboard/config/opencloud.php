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

    /*
     * Let's Encrypt sends expiry notices to this address. It is optional.
     */
    'acme_email' => env('OPENCLOUD_ACME_EMAIL'),

    /*
     * The public IPv4 address of the dashboard VPS. Bootstrap stores it as the ingress IP of the local cluster.
     */
    'public_ip' => env('OPENCLOUD_PUBLIC_IP'),

    'registry_host' => 'registry.opencloud.svc.cluster.local:5000',

    'buildkit_addr' => 'tcp://buildkitd.opencloud.svc.cluster.local:1234',

    /*
     * The base image of a Laravel app. "{php}" becomes the PHP minor version, for example 8.4.
     */
    'php_base_image' => env('OPENCLOUD_PHP_BASE_IMAGE', 'dunglas/frankenphp:1-php{php}'),

    /*
     * The PHP minor versions that have a base image. The build picks the highest one that composer.json allows.
     */
    'php_versions' => ['8.2', '8.3', '8.4'],

    'data_path' => env('OPENCLOUD_DATA_PATH', storage_path('opencloud')),

];
