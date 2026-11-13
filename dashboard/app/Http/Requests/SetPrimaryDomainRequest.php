<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPrimaryDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('domain')->environment->application);
    }
}
