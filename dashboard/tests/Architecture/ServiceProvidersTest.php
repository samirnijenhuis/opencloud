<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\ServiceProvider;

final class ServiceProvidersTest extends ArchitectureTestCase
{
    public function test_service_providers_end_with_service_provider(): void
    {
        $this->assertMatchesBaseline(
            'providers.service-provider-suffix',
            'End a service provider name with ServiceProvider: AppServiceProvider. See .claude/rules/naming.md.',
            $this->namedWithout($this->subclassesOf(ServiceProvider::class), 'ServiceProvider'),
        );
    }

    public function test_service_providers_live_in_the_providers_directory(): void
    {
        $this->assertMatchesBaseline(
            'providers.placement',
            'Put a service provider in app/Providers. See .claude/rules/architecture.md.',
            $this->placedOutside(
                $this->subclassesOf(ServiceProvider::class),
                '/^App\\\\Providers\\\\\w+$/',
            ),
        );
    }
}
