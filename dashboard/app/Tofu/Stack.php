<?php

namespace App\Tofu;

use InvalidArgumentException;

/**
 * One OpenTofu root module and its state, for example the cluster with id 5. The Runner keeps its work folder at
 * <data_path>/tofu/<name>.
 */
final readonly class Stack
{
    /**
     * @param  string  $module  the module folder under opencloud.tofu_modules, for example "digitalocean/cluster"
     * @param  string  $name  the work folder, "<kind>-<id>", for example "cluster-5"
     * @param  array<string, string|int|bool>  $variables  the values of the module variables
     * @param  array<string, string>  $credentials  the provider credentials, which go into the process env only
     */
    public function __construct(
        public string $module,
        public string $name,
        public array $variables,
        public array $credentials,
    ) {
        if (preg_match('/^[a-z]+-[1-9][0-9]*$/', $name) !== 1) {
            throw new InvalidArgumentException("The stack name [{$name}] is not <kind>-<id>.");
        }
    }
}
