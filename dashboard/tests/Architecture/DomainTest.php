<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PhpParser\Node;
use PHPUnit\Architecture\Elements\Layer\Layer;

final class DomainTest extends ArchitectureTestCase
{
    public function test_domains_do_not_depend_on_the_entry_points(): void
    {
        $this->assertMatchesBaseline(
            'domain.no-app-dependency',
            'A domain does not use App\Http or App\Console classes; the controller or command passes data into the domain. See .claude/rules/architecture.md.',
            [
                ...$this->dependenciesOn($this->domain(), 'App\\Http\\'),
                ...$this->dependenciesOn($this->domain(), 'App\\Console\\'),
            ],
        );
    }

    public function test_domains_do_not_locate_services(): void
    {
        $this->assertMatchesBaseline(
            'domain.no-service-location',
            'Inject the dependency in the constructor instead of app(X::class), app()->make() or resolve(). See .claude/rules/actions-jobs.md.',
            $this->find($this->domain(), fn (Node $node): bool => ($this->isFunctionCall($node, 'app') && $node->args !== [])
                || $this->isFunctionCall($node, 'resolve')
                || ($this->isMethodCall($node, 'make', 'makeWith') && $this->isFunctionCall($node->var, 'app'))),
        );
    }

    public function test_domains_do_not_log_through_the_facade(): void
    {
        $this->assertMatchesBaseline(
            'domain.no-log-facade',
            'Inject Psr\Log\LoggerInterface instead of Log::. See .claude/rules/actions-jobs.md.',
            $this->find($this->domain(), fn (Node $node): bool => $this->isStaticCall($node, Log::class)),
        );
    }

    public function test_domains_do_not_depend_on_http(): void
    {
        $this->assertMatchesBaseline(
            'domain.no-http',
            'Keep domains free of the request: the controller reads the request, session or response and passes plain values in. See .claude/rules/architecture.md.',
            [
                ...$this->dependenciesOn($this->domain(), 'Illuminate\\Http\\', ['Illuminate\\Http\\Client\\', UploadedFile::class, File::class]),
                ...$this->dependenciesOn($this->domain(), 'Inertia\\'),
                ...$this->find($this->domain(), fn (Node $node): bool => $this->isFunctionCall($node, 'request', 'session', 'redirect', 'response', 'back', 'view')),
            ],
        );
    }

    public function test_domains_inject_dependencies_instead_of_facades(): void
    {
        $this->assertMatchesBaseline(
            'domain.no-facades',
            'Inject the dependency in the constructor instead of calling a facade. See .claude/rules/actions-jobs.md.',
            $this->find($this->domain(), fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && ! in_array($this->facade($node->class->toString()), [null, Log::class], true)),
        );
    }

    private function facade(string $class): ?string
    {
        if (! str_contains($class, '\\') && class_exists("Illuminate\\Support\\Facades\\{$class}")) {
            $class = "Illuminate\\Support\\Facades\\{$class}";
        }

        return class_exists($class) && is_subclass_of($class, Facade::class) ? $class : null;
    }

    private function domain(): Layer
    {
        return $this->classes('/^App\\\\Domains\\\\/');
    }
}
