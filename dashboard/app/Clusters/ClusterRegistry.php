<?php

namespace App\Clusters;

/**
 * The registry near a cluster other than local. That cluster pulls its images from here, so it can start pods while the dashboard VPS is down.
 */
final readonly class ClusterRegistry
{
    /** @param  string  $address  host, optional port, and optional path, for example "registry.example.com/team" */
    public function __construct(
        public string $address,
        public string $username,
        public string $password,
        public bool $insecure,
    ) {}

    /** Accepts a pasted "https://registry.example.com/team/" and keeps "registry.example.com/team". */
    public static function normalizeAddress(string $address): string
    {
        $address = preg_replace('#^[a-z]+://#i', '', trim($address));

        return rtrim($address, '/');
    }

    /** The registry host and port, which credentials are keyed on. */
    public function host(): string
    {
        return explode('/', $this->address, 2)[0];
    }

    /**
     * The Docker config with the credentials. crane reads it as config.json, and the pull secret holds it as .dockerconfigjson.
     *
     * @return array{auths: array<string, array{username: string, password: string, auth: string}>}
     */
    public function dockerConfig(): array
    {
        return ['auths' => [$this->host() => [
            'username' => $this->username,
            'password' => $this->password,
            'auth' => base64_encode("{$this->username}:{$this->password}"),
        ]]];
    }
}
