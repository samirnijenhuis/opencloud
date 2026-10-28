<?php

namespace App\Domains\Environments\Actions;

use App\Domains\Environments\DotenvText;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

class SaveEnvText
{
    public function __construct(
        private DotenvText $dotenv,
        private SetEnvVar $setEnvVar,
        private DeleteEnvVar $deleteEnvVar,
        private ConnectionInterface $db,
    ) {}

    /**
     * Makes the plain user values of the environment equal to the text. The text never changes a locked value:
     * a value that a resource sets, or a secret.
     *
     * @throws ValidationException with a message for each bad line, before any change
     */
    public function execute(Environment $environment, User $by, string $text): void
    {
        $envVars = $environment->envVars()->get();
        $locked = $envVars->filter(fn (EnvVar $envVar) => $envVar->source === EnvVarSource::DATASTORE || $envVar->is_secret);
        $lockedByKey = $locked->sortBy(fn (EnvVar $envVar) => $envVar->source === EnvVarSource::DATASTORE ? 1 : 0)->keyBy('key');
        $plain = $envVars->diff($locked)->reject(fn (EnvVar $envVar) => $lockedByKey->has($envVar->key))->keyBy('key');

        // The page shows the locked rows above the text, so its line numbers continue after them.
        $parsed = $this->dotenv->parse($text, $locked->count() + 1);
        $errors = $parsed->errors;

        foreach ($parsed->lines as $key => $line) {
            if ($lockedByKey->has($key)) {
                $errors[] = $lockedByKey[$key]->source === EnvVarSource::DATASTORE
                    ? "Line {$line} uses {$key}. A resource sets this key."
                    : "Line {$line} uses {$key}. This key holds a secret. Replace it in the table.";
            }
        }

        if ($errors !== []) {
            sort($errors, SORT_NATURAL);

            throw ValidationException::withMessages(['text' => implode("\n", $errors)]);
        }

        // One transaction, so a save that stops halfway leaves the values as they were.
        $this->db->transaction(function () use ($environment, $by, $parsed, $plain) {
            foreach ($parsed->values as $key => $value) {
                $current = $plain->get($key);

                if ($current?->value !== $value) {
                    $this->setEnvVar->execute($environment, $by, $key, $value, false, $current?->available_at ?? EnvVarAvailability::RUNTIME);
                }
            }

            foreach ($plain->diffKeys($parsed->values) as $envVar) {
                $this->deleteEnvVar->execute($envVar, $by);
            }
        });
    }
}
