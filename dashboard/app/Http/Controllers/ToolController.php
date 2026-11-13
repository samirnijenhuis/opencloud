<?php

namespace App\Http\Controllers;

use App\Domains\Environments\Actions\AddTool;
use App\Domains\Environments\Actions\RemoveTool;
use App\Domains\Environments\Actions\UpdateTool;
use App\Domains\Environments\Enums\ToolKind;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\Tool;
use App\Http\Requests\AddToolRequest;
use App\Http\Requests\RemoveToolRequest;
use App\Http\Requests\UpdateToolRequest;
use Illuminate\Http\RedirectResponse;

class ToolController extends Controller
{
    public function store(AddToolRequest $request, Environment $environment, AddTool $addTool): RedirectResponse
    {
        $addTool->execute($environment, $request->user(), $request->enum('kind', ToolKind::class), $request->validated('hostname'));

        return back();
    }

    public function update(UpdateToolRequest $request, Tool $tool, UpdateTool $updateTool): RedirectResponse
    {
        $updateTool->execute($tool, $request->user(), $request->validated('hostname'));

        return back();
    }

    public function destroy(RemoveToolRequest $request, Tool $tool, RemoveTool $removeTool): RedirectResponse
    {
        $removeTool->execute($tool, $request->user());

        return back();
    }
}
