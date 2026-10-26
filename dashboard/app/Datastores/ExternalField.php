<?php

namespace App\Datastores;

/**
 * One field of the form for a resource that the user runs. The form shows it, and the attach request validates it
 * with the same rules, so the two never drift apart.
 */
final readonly class ExternalField
{
    /**
     * @param  'text'|'number'|'secret'|'toggle'  $type  a secret never comes back to the browser
     * @param  list<mixed>  $rules  the Laravel rules after "required" or "nullable"
     * @param  string  $error  the message for every rule of the field, so an empty and a bad value read the same
     * @param  string|int|bool|null  $value  the value that the form starts with
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public bool $required,
        public array $rules,
        public string $error,
        public string|int|bool|null $value = null,
        public ?string $placeholder = null,
        public ?string $help = null,
    ) {}

    /** @return array<string, mixed> what the form needs. The rules stay on the server. */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'value' => $this->value,
            'placeholder' => $this->placeholder,
            'help' => $this->help,
        ];
    }
}
