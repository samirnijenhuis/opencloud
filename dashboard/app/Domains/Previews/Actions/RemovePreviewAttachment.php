<?php

namespace App\Domains\Previews\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Models\User;
use Illuminate\Validation\ValidationException;

class RemovePreviewAttachment
{
    /**
     * Removes one resource from the list of the preview template. Only new previews lose it. A preview that runs keeps
     * its resources until its pull request closes. An entry that the list no longer has needs no change.
     *
     * @throws ValidationException for the default database or bucket while the list has another one of its family
     */
    public function execute(Environment $template, User $by, DatastoreKind $kind, ?string $name): void
    {
        $entries = collect($template->preview_attachments);
        $entry = $entries->first(fn (array $entry) => $entry['kind'] === $kind->value && $entry['name'] === $name);

        if ($entry === null) {
            return;
        }

        $family = $kind->family();
        $others = $entries->reject(fn (array $each) => $each === $entry);

        if ($entry['is_default'] && $others->contains(fn (array $each) => DatastoreKind::from($each['kind'])->family() === $family)) {
            throw ValidationException::withMessages(['detach' => "This is the default {$family->value} of each preview. Remove the other {$family->value}s first."]);
        }

        $template->update(['preview_attachments' => $others->values()->all()]);

        AuditEvent::record($by, 'preview_template.attachment_removed', $template, array_filter([
            'kind' => $kind->value,
            'name' => $name,
        ]));
    }
}
