<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Models\Environment;
use App\Domains\Previews\Actions\AddPreviewAttachment;
use App\Domains\Previews\Actions\RemovePreviewAttachment;
use App\Http\Requests\AddPreviewAttachmentRequest;
use App\Http\Requests\RemovePreviewAttachmentRequest;
use Illuminate\Http\RedirectResponse;

/** The list of resources on the preview template that each new preview gets. */
class PreviewAttachmentController extends Controller
{
    public function store(AddPreviewAttachmentRequest $request, Environment $environment, AddPreviewAttachment $addPreviewAttachment): RedirectResponse
    {
        $addPreviewAttachment->execute($environment, $request->user(), $request->kind(), $request->validated('name'));

        return back();
    }

    public function destroy(RemovePreviewAttachmentRequest $request, Environment $environment, RemovePreviewAttachment $removePreviewAttachment): RedirectResponse
    {
        $removePreviewAttachment->execute($environment, $request->user(), $request->kind(), $request->validated('name'));

        return back();
    }
}
