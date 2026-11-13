<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WakeEnvironmentRequest extends FormRequest
{
    /**
     * A visitor of a site has no account. The route finds the domain by the hostname of the request, so a request can wake
     * only the environment that has that hostname.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
