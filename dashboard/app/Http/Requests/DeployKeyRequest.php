<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Adding, replacing, and removing the deploy key take no input, so they share the one check. */
class DeployKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('application'));
    }
}
