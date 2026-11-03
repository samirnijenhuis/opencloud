<?php

namespace App\Domains\Previews\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\ResourceFamily;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use Illuminate\Validation\ValidationException;

class AddPreviewAttachment
{
    /**
     * Adds one resource to the list of the preview template. Each new preview gets a standard resource for each entry.
     * The template itself gets nothing, because it never deploys.
     *
     * The rules are those of AttachDatastore. A database or a bucket has a name that is unique in its family. The first
     * one of its family is the default and is named "main" without $name. A later one needs a name. A preview has at
     * most one key-value store.
     *
     * @throws ValidationException when the name is not valid or taken, or the list already has a key-value store
     */
    public function execute(Environment $template, User $by, DatastoreKind $kind, ?string $name = null): void
    {
        $family = $kind->family();
        $named = $family->isNamed();
        $entries = collect($template->preview_attachments)->filter(fn (array $entry) => DatastoreKind::from($entry['kind'])->family() === $family);
        $name = $named ? $name ?? ($entries->isEmpty() ? ResourceFamily::FIRST_NAME : null) : null;

        $error = match (true) {
            ! $named => $entries->isEmpty() ? null : "Each preview already gets a {$entries->first()['kind']} datastore.",
            $name === null => "Each preview already gets a {$family->value}. Give the new {$family->value} a name.",
            $entries->contains('name', $name) => "Each preview already gets a {$family->value} with the name {$name}.",
            default => $family->nameError($name),
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$named ? 'name' : 'kind' => $error]);
        }

        $template->update(['preview_attachments' => [
            ...$template->preview_attachments,
            ['kind' => $kind->value, 'name' => $name, 'is_default' => $named && $entries->isEmpty()],
        ]]);

        AuditEvent::record($by, 'preview_template.attachment_added', $template, array_filter(['kind' => $kind->value, 'name' => $name]));
    }
}
