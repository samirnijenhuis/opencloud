<?php

namespace App\Http\Controllers;

use App\Actions\AttachDatastore;
use App\Actions\DetachDatastore;
use App\Enums\Ability;
use App\Enums\DatastoreKind;
use App\Enums\DatastoreSource;
use App\Http\Requests\AttachDatastoreRequest;
use App\Models\DatastoreAttachment;
use App\Models\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class DatastoreController extends Controller
{
    /** The canvas shows this now. Old links still work. */
    public function index(Environment $environment): RedirectResponse
    {
        return to_route('environments.show', $environment);
    }

    public function store(AttachDatastoreRequest $request, Environment $environment, AttachDatastore $attachDatastore): RedirectResponse
    {
        try {
            $attachDatastore->handle($environment, $request->user(), DatastoreKind::from($request->validated('kind')), $request->source());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['kind' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Request $request, DatastoreAttachment $attachment, DetachDatastore $detachDatastore): RedirectResponse
    {
        Gate::authorize('update', $attachment->environment);

        if ($attachment->datastore->source === DatastoreSource::Managed) {
            abort_unless($request->user()->role->allows(Ability::ManageCredentials), 403);
            $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that DigitalOcean deletes the database and its data.']);
        }

        $detachDatastore->handle($attachment, $request->user());

        return back();
    }
}
