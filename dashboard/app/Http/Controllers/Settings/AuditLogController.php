<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\EnvVar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->role->allows(Ability::ManageApplications), 403);

        return Inertia::render('settings/audit', [
            'events' => AuditEvent::query()
                ->with(['user:id,name', 'subject'])
                ->latest('id')
                ->paginate(25)
                ->through(fn (AuditEvent $event) => [
                    'id' => $event->id,
                    'user' => $event->user?->name,
                    'action' => $event->action,
                    'subject' => $this->describe($event),
                    'created_at' => $event->created_at->toIso8601String(),
                ]),
        ]);
    }

    private function describe(AuditEvent $event): ?string
    {
        if ($event->subject_type === null) {
            return null;
        }

        $type = str(class_basename($event->subject_type))->snake(' ')->lower()->toString();
        $subject = $event->subject;

        if (! $subject instanceof Model) {
            return "{$type} #{$event->subject_id} (deleted)";
        }

        $label = match (true) {
            $subject instanceof Domain => $subject->hostname,
            $subject instanceof EnvVar => $subject->key,
            default => $subject->getAttribute('name'),
        };

        return $label === null ? "{$type} #{$subject->getKey()}" : "{$type} {$label}";
    }
}
