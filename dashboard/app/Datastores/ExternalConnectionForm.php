<?php

namespace App\Datastores;

use App\Enums\DatastoreKind;
use App\Enums\ResourceFamily;

/**
 * The address that a user types for a resource that they run, per family: the fields of the form, their rules, and
 * the typed connection that the external provisioner stores. The secret of each family has the key "password", so
 * Laravel neither trims it nor flashes it into the session.
 */
final class ExternalConnectionForm
{
    /** The port that the form starts with. */
    private const PORTS = ['mysql' => 3306, 'postgres' => 5432, 'valkey' => 6379];

    /** A host name or an IPv4 address. A scheme, a port, or an IPv6 address would break the URL that a profile builds. */
    private const HOST = '/^(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\z/';

    /** @return list<ExternalField> */
    public static function fields(DatastoreKind $kind): array
    {
        return match ($kind->family()) {
            ResourceFamily::Database => [
                self::host('db.example.com'),
                self::port($kind),
                // The database name goes into a URL without encoding, so it has no character that a URL reserves.
                new ExternalField('database', 'Database', 'text', true, ['string', 'regex:/^[A-Za-z0-9_-]{1,63}\z/'], 'Enter the database name with 1 to 63 letters, digits, underscores, and dashes.', placeholder: 'shop'),
                new ExternalField('username', 'User', 'text', true, ['string', 'max:255', 'regex:/^\S+\z/'], 'Enter the user name without spaces.'),
                new ExternalField('password', 'Password', 'secret', true, ['string', 'max:1024'], 'Enter the password of the user.'),
            ],
            ResourceFamily::KeyValue => [
                self::host('cache.example.com'),
                self::port($kind),
                new ExternalField('password', 'Password', 'secret', false, ['string', 'max:1024'], 'Use at most 1024 characters for the password.', help: 'Leave it empty when the server has no password.'),
                new ExternalField('tls', 'Use TLS', 'toggle', false, ['boolean'], 'Turn TLS on or off.', value: false),
            ],
            ResourceFamily::Bucket => [
                new ExternalField('endpoint', 'Endpoint URL', 'text', true, ['string', 'max:255', 'url:http,https', 'regex:~^https?://[^/?#@\s]+/?\z~'], 'Enter the endpoint URL with http:// or https:// and no path, for example https://s3.eu-central-1.amazonaws.com.', placeholder: 'https://s3.eu-central-1.amazonaws.com'),
                new ExternalField('region', 'Region', 'text', true, ['string', 'regex:/^[a-z0-9-]{1,64}\z/'], 'Enter the region with lowercase letters, digits, and dashes, for example eu-central-1.', value: 'us-east-1', help: 'S3 clients sign each request with it. Most S3-compatible servers ignore it.'),
                new ExternalField('bucket', 'Bucket', 'text', true, ['string', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/'], 'Enter the bucket name with 3 to 63 lowercase letters, digits, dots, and dashes.', placeholder: 'shop-uploads'),
                new ExternalField('access_key_id', 'Access key ID', 'text', true, ['string', 'max:255', 'regex:/^\S+\z/'], 'Enter the access key ID without spaces.'),
                new ExternalField('password', 'Secret access key', 'secret', true, ['string', 'max:1024'], 'Enter the secret access key.'),
                new ExternalField('path_style', 'Use path-style URLs', 'toggle', false, ['boolean'], 'Turn path-style URLs on or off.', value: false, help: 'Turn it on for MinIO and most servers that you host yourself.'),
            ],
        };
    }

    /** @return array<string, list<mixed>> */
    public static function rules(DatastoreKind $kind): array
    {
        return collect(self::fields($kind))
            ->mapWithKeys(fn (ExternalField $field) => [$field->key => [$field->required ? 'required' : 'nullable', ...$field->rules]])
            ->all();
    }

    /** @return array<string, string> one message per field, for every rule of it */
    public static function messages(DatastoreKind $kind): array
    {
        return collect(self::fields($kind))->mapWithKeys(fn (ExternalField $field) => [$field->key => $field->error])->all();
    }

    /**
     * The connection that the user typed, from values that rules() passed.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $name  the typed connection name. The action decides the real name and the default.
     */
    public static function connection(DatastoreKind $kind, array $values, ?string $name): Connection
    {
        return match ($kind->family()) {
            ResourceFamily::Database => new SqlConnection($kind, $values['host'], (int) $values['port'], $values['database'], $values['username'], $values['password'], $name ?? DatabaseNames::FIRST_NAME, isDefault: false),
            ResourceFamily::KeyValue => new KeyValueConnection($values['host'], (int) $values['port'], $values['password'] ?? null, (bool) ($values['tls'] ?? false)),
            ResourceFamily::Bucket => new BucketConnection(rtrim($values['endpoint'], '/'), $values['region'], $values['bucket'], $values['access_key_id'], $values['password'], (bool) ($values['path_style'] ?? false)),
        };
    }

    private static function host(string $placeholder): ExternalField
    {
        return new ExternalField('host', 'Host', 'text', true, ['string', 'regex:'.self::HOST], 'Enter a host name or an IPv4 address without a scheme or a port, for example db.example.com.', placeholder: $placeholder);
    }

    private static function port(DatastoreKind $kind): ExternalField
    {
        return new ExternalField('port', 'Port', 'number', true, ['integer', 'between:1,65535'], 'Enter a port from 1 to 65535.', value: self::PORTS[$kind->value]);
    }
}
