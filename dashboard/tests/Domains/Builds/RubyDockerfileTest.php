<?php

namespace Tests\Domains\Builds;

use App\Domains\Builds\Exceptions\RepositoryDoesNotFit;
use App\Domains\Builds\RepositoryFiles;
use App\Domains\Builds\RubyDockerfile;
use App\Domains\Builds\RubyProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RubyDockerfileTest extends TestCase
{
    private const RAILS = ['bin/rails' => "#!/usr/bin/env ruby\n", 'config/application.rb' => "module Shop\nend\n", 'config.ru' => "run Rails.application\n"];

    public function test_a_rails_app_with_propshaft_gets_the_ruby_of_its_pin_bundle_install_the_assets_the_build_command_and_rails_server(): void
    {
        $project = self::project([
            '.ruby-version' => "ruby-3.3.6\n",
            'Gemfile' => "source \"https://rubygems.org\"\nruby \"~> 3.4\"\ngem \"rails\", \"~> 8.1\"\ngem \"propshaft\"\ngem \"puma\"\n",
            'Gemfile.lock' => "GEM\n  specs:\n    propshaft (1.3.1)\n    puma (7.1.0)\n",
            ...self::RAILS,
        ]);

        $this->assertSame(<<<'DOCKERFILE'
            FROM ruby:3.3.6
            # tini runs as PID 1 and passes SIGTERM to the app.
            RUN apt-get update && apt-get install -y --no-install-recommends tini && rm -rf /var/lib/apt/lists/*
            # Bundler.setup also skips these groups when the app starts, so it does not look for gems that the build left out.
            ENV BUNDLE_WITHOUT=development:test RACK_ENV=production RAILS_ENV=production
            # Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
            ARG OPENCLOUD_BUILD_ENV
            WORKDIR /app
            COPY . .
            RUN --mount=type=cache,target=/usr/local/bundle/cache --mount=type=secret,id=BUNDLE_GEMS__CONTRIBSYS__COM,env=BUNDLE_GEMS__CONTRIBSYS__COM bundle install
            RUN --mount=type=secret,id=BUNDLE_GEMS__CONTRIBSYS__COM,env=BUNDLE_GEMS__CONTRIBSYS__COM SECRET_KEY_BASE_DUMMY=1 bin/rails assets:precompile
            RUN --mount=type=secret,id=BUNDLE_GEMS__CONTRIBSYS__COM,env=BUNDLE_GEMS__CONTRIBSYS__COM bin/rails sitemap:create
            ENTRYPOINT ["/usr/bin/tini", "--"]
            CMD ["sh", "-c", "exec bin/rails server -b 0.0.0.0 -p $PORT"]

            DOCKERFILE, RubyDockerfile::render($project, ['BUNDLE_GEMS__CONTRIBSYS__COM'], ' bin/rails sitemap:create '));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function versions(): array
    {
        $gemfile = fn (string $ruby) => ['Gemfile' => "source \"https://rubygems.org\"\n\nruby '{$ruby}'\ngem 'sinatra'\n"];

        return [
            'no pin and no ruby line' => [['Gemfile' => "gem 'sinatra'\n"], '3.4'],
            'a minor in .ruby-version' => [['.ruby-version' => "4.0\n", ...$gemfile('3.2.9')], '4.0'],
            'an exact ruby line' => [$gemfile('3.2.9'), '3.2.9'],
            'a pessimistic ruby line that allows the default' => [$gemfile('~> 3.2'), '3.4'],
            'a pessimistic ruby line on a patch' => [$gemfile('~> 3.3.0'), '3.3'],
            'a ruby line that allows 4.0 only' => [$gemfile('>= 4.0'), '4.0'],
            'the ruby line reads .ruby-version' => [['.ruby-version' => '3.3.1', 'Gemfile' => "ruby file: \".ruby-version\"\n"], '3.3.1'],
        ];
    }

    /** @param  array<string, string>  $files */
    #[DataProvider('versions')]
    public function test_the_pin_or_the_ruby_line_picks_the_ruby_image(array $files, string $expected): void
    {
        $dockerfile = RubyDockerfile::render(self::project(['Procfile' => "web: ruby app.rb\n", ...$files]));

        $this->assertSame("FROM ruby:{$expected}", strtok($dockerfile, "\n"));
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function startCommands(): array
    {
        return [
            'the web line of the Procfile wins over Rails' => [
                ['Procfile' => "web: bundle exec puma -C config/puma.rb\nworker: bundle exec sidekiq\n", 'Gemfile' => "gem 'rails'\ngem 'sprockets-rails'\n", ...self::RAILS],
                'CMD ["sh", "-c", "exec bundle exec puma -C config/puma.rb"]',
                'RUN SECRET_KEY_BASE_DUMMY=1 bin/rails assets:precompile',
            ],
            'an api-only rails app has no assets' => [
                ['Gemfile' => "gem 'rails'\ngem 'puma'\n", ...self::RAILS],
                'CMD ["sh", "-c", "exec bin/rails server -b 0.0.0.0 -p $PORT"]',
                '',
            ],
            'a config.ru with puma' => [
                ['Gemfile' => "gem 'sinatra'\n", 'Gemfile.lock' => "GEM\n  specs:\n    puma (7.1.0)\n    sinatra (4.2.1)\n", 'config.ru' => "run App\n"],
                'CMD ["sh", "-c", "exec bundle exec puma -b tcp://0.0.0.0:$PORT"]',
                '',
            ],
            'a config.ru without puma uses rackup' => [
                ['Gemfile' => "gem 'roda'\ngem 'puma-status'\ngem 'rackup'\n", 'config.ru' => "run App.freeze.app\n"],
                'CMD ["sh", "-c", "exec bundle exec rackup --host 0.0.0.0 --port $PORT"]',
                '',
            ],
            'propshaft without rails gives no assets' => [
                ['Gemfile' => "gem 'propshaft'\ngem 'puma'\n", 'config.ru' => "run App\n"],
                'CMD ["sh", "-c", "exec bundle exec puma -b tcp://0.0.0.0:$PORT"]',
                '',
            ],
        ];
    }

    /** @param  array<string, string>  $files */
    #[DataProvider('startCommands')]
    public function test_the_procfile_or_the_framework_picks_the_start_command_and_rails_with_assets_precompiles_them(array $files, string $start, string $assets): void
    {
        $lines = explode("\n", RubyDockerfile::render(self::project($files)));

        $this->assertSame([$start], array_values(preg_grep('/^CMD /', $lines)));
        $this->assertSame($assets === '' ? [] : [$assets], array_values(preg_grep('/assets:precompile/', $lines)));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function projectsThatCannotBuild(): array
    {
        return [
            'no Procfile, no Rails, and no config.ru' => [
                ['Gemfile' => "gem 'sidekiq'\n", 'Procfile' => "worker: bundle exec sidekiq\n", 'bin/rails' => ''],
                'The repository has no Procfile with a web line, no Rails app, and no config.ru, so opencloud does not know how to start the app. Add a Procfile with a web line, for example "web: bundle exec puma -b tcp://0.0.0.0:$PORT". To use another preset, create a new application with the same repository.',
            ],
            'a ruby version without an image' => [
                ['.ruby-version' => "2.7.8\n", 'Gemfile' => '', 'config.ru' => ''],
                '.ruby-version requires Ruby 2.7.8. Opencloud builds with Ruby 3.2, 3.3, 3.4, 4.0.',
            ],
            'a ruby line without an image' => [
                ['Gemfile' => "ruby '~> 2.7'\n", 'config.ru' => ''],
                'The Gemfile requires Ruby ~> 2.7. Opencloud builds with Ruby 3.2, 3.3, 3.4, 4.0.',
            ],
        ];
    }

    /** @param  array<string, string>  $files */
    #[DataProvider('projectsThatCannotBuild')]
    public function test_a_project_that_opencloud_cannot_build_fails_with_a_plain_message(array $files, string $message): void
    {
        try {
            RubyDockerfile::render(self::project($files));
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(str_ends_with($message, 'same repository.'), $e instanceof RepositoryDoesNotFit);

            return;
        }

        $this->fail('The render did not fail.');
    }

    public function test_a_repository_without_a_gemfile_is_no_ruby_project(): void
    {
        $this->assertNull(RubyProject::in(new RepositoryFiles(fn (string $path) => $path === 'Gemfile.lock' ? '' : null)));
    }

    /** @param  array<string, string>  $files */
    private static function project(array $files): RubyProject
    {
        return RubyProject::in(new RepositoryFiles(fn (string $path) => $files[$path] ?? null));
    }
}
