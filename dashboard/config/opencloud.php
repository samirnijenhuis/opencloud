<?php

return [

    'version' => env('OPENCLOUD_VERSION', 'dev'),

    /*
     * The GitHub repository of opencloud. The dashboard asks its releases API once a day for a newer release.
     */
    'repository' => env('OPENCLOUD_REPO', 'opencloud-dev/opencloud'),

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

    /*
     * The fixed ClusterIP of the local registry. crane reads from this address when it copies an image to another cluster.
     * crane talks plain HTTP to a private IP by itself. Its --insecure flag would also drop the TLS checks of the target registry.
     */
    'registry_address' => '10.43.200.200:5000',

    /*
     * Caddy on clusters other than local. Keep it the same as the image in platform/caddy.yaml.
     */
    'caddy_image' => env('OPENCLOUD_CADDY_IMAGE', 'caddy:2.11.7'),

    // Empty means the running BuildKit pod, reached through kubectl exec.
    'buildkit_addr' => env('OPENCLOUD_BUILDKIT_ADDR'),

    /*
     * The base image of a Laravel app. "{php}" becomes the PHP minor version, for example 8.4.
     */
    'php_base_image' => env('OPENCLOUD_PHP_BASE_IMAGE', 'dunglas/frankenphp:1-php{php}'),

    /*
     * The PHP minor versions that have a base image. The build picks LaravelDockerfile::DEFAULT_PHP when composer.json
     * allows it, and else the highest one that composer.json allows.
     */
    'php_versions' => ['8.2', '8.3', '8.4', '8.5'],

    'data_path' => env('OPENCLOUD_DATA_PATH', storage_path('opencloud')),

    /*
     * The most previews that one application runs at the same time, to protect the VPS.
     */
    'max_previews' => (int) env('OPENCLOUD_MAX_PREVIEWS', 5),

    'tofu_binary' => env('OPENCLOUD_TOFU_BINARY', 'tofu'),

    /*
     * The OpenTofu modules of the repository. The image copies them to /opt/opencloud/tofu.
     */
    'tofu_modules' => env('OPENCLOUD_TOFU_MODULES', base_path('../tofu')),

    /*
     * The size of a managed database on DigitalOcean. https://www.digitalocean.com/pricing/managed-databases
     */
    'managed_database_size' => env('OPENCLOUD_MANAGED_DATABASE_SIZE', 'db-s-1vcpu-1gb'),

    /*
     * The size of a managed Valkey on DigitalOcean. A Spaces bucket has no size.
     */
    'managed_valkey_size' => env('OPENCLOUD_MANAGED_VALKEY_SIZE', 'db-s-1vcpu-1gb'),

];
