<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Inertia's default path is js/Pages. That only resolves on a case-insensitive disk.
        config(['inertia.testing.page_paths' => [resource_path('js/pages')]]);
    }
}
