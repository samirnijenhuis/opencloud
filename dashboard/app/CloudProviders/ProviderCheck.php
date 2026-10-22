<?php

namespace App\CloudProviders;

interface ProviderCheck
{
    /** Makes read-only API calls with the pasted key. It never changes anything at the provider. */
    public function check(string $token): CheckResult;
}
