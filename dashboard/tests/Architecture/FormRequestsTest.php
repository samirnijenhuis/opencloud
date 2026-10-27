<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Http\FormRequest;

final class FormRequestsTest extends ArchitectureTestCase
{
    public function test_form_requests_live_in_the_http_requests_directory(): void
    {
        $this->assertMatchesBaseline(
            'requests.placement',
            'Put a Form Request in app/Http/Requests. See .claude/rules/architecture.md.',
            $this->placedOutside($this->subclassesOf(FormRequest::class), '/^App\\\\Http\\\\Requests\\\\/'),
        );
    }

    public function test_form_requests_end_with_request(): void
    {
        $this->assertMatchesBaseline(
            'requests.request-suffix',
            'End a Form Request name with Request: CreateClusterRequest. See .claude/rules/naming.md.',
            $this->namedWithout($this->subclassesOf(FormRequest::class), 'Request'),
        );
    }
}
