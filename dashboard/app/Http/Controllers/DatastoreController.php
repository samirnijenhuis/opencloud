<?php

namespace App\Http\Controllers;

use App\Domains\Datastores\Actions\AttachDatastore;
use App\Domains\Datastores\Actions\DetachDatastore;
use App\Domains\Datastores\Actions\SetDefaultDatabase;
use App\Domains\Datastores\Enums\DatastoreKind;
use App\Domains\Datastores\Enums\DatastoreSource;
use App\Domains\Datastores\Models\DatastoreAttachment;
use App\Domains\Environments\Models\Environment;
use App\Domains\Members\Enums\Ability;
use App\Http\Requests\AttachDatastoreRequest;
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
            $attachDatastore->handle($environment, $request->user(), DatastoreKind::from($request->validated('kind')), $request->source(), $request->validated('name'), $request->connection());
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
            $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that you want to destroy this resource and its data.']);
        }

        try {
            $detachDatastore->handle($attachment, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['detach' => $e->getMessage()]);
        }

        return back();
    }

    public function makeDefault(Request $request, DatastoreAttachment $attachment, SetDefaultDatabase $setDefaultDatabase): RedirectResponse
    {
        Gate::authorize('update', $attachment->environment);

        try {
            $setDefaultDatabase->handle($attachment, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['default' => $e->getMessage()]);
        }

        return back();
    }
}
