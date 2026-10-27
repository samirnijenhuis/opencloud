<?php

namespace App\Build;

use RuntimeException;

/** The Gemfile, the Ruby version, and the start command of a Ruby app. */
final readonly class RubyProject
{
    /** The minors that have an official image. */
    public const RUBY_VERSIONS = ['3.2', '3.3', '3.4', '4.0'];

    /** The newest Ruby 3, so an app without a pin does not meet the changes of the major version 4.0. */
    public const DEFAULT_RUBY = '3.4';

    private const GEM_FILES = ['Gemfile', 'Gemfile.lock'];

    private function __construct(private RepositoryFiles $files) {}

    public static function in(RepositoryFiles $files): ?self
    {
        return $files->has('Gemfile') ? new self($files) : null;
    }

    /**
     * .ruby-version wins over the ruby line of the Gemfile.
     *
     * @throws RuntimeException when the project asks for a Ruby without an image
     */
    public function rubyVersion(): string
    {
        $pinned = trim((string) $this->files->read('.ruby-version'));

        if ($pinned !== '') {
            return RuntimeVersion::tag('Ruby', '.ruby-version', preg_replace('/^ruby-/', '', $pinned), self::RUBY_VERSIONS, self::DEFAULT_RUBY);
        }

        if (preg_match('/^\s*ruby\s+["\']([^"\']+)["\']/m', (string) $this->files->read('Gemfile'), $match) === 1) {
            return RuntimeVersion::tag('Ruby', 'The Gemfile', $match[1], self::RUBY_VERSIONS, self::DEFAULT_RUBY);
        }

        return self::DEFAULT_RUBY;
    }

    public function isRails(): bool
    {
        return $this->files->has('bin/rails') && $this->files->has('config/application.rb');
    }

    /** Propshaft and sprockets-rails give a Rails app the task assets:precompile. An API-only app has neither. */
    public function hasAssets(): bool
    {
        return $this->isRails() && ($this->depends('propshaft') || $this->depends('sprockets-rails'));
    }

    /**
     * The web line of the Procfile, else bin/rails server for Rails, else puma or rackup for a config.ru. The server
     * listens on 0.0.0.0 and on PORT.
     *
     * @throws RepositoryDoesNotFit when none of these fits
     */
    public function start(): string
    {
        return $this->files->procfileWeb() ?? match (true) {
            $this->isRails() => 'bin/rails server -b 0.0.0.0 -p $PORT',
            ! $this->files->has('config.ru') => throw new RepositoryDoesNotFit(
                'The repository has no Procfile with a web line, no Rails app, and no config.ru, so opencloud does not know how to start the app. Add a Procfile with a web line, for example "web: bundle exec puma -b tcp://0.0.0.0:$PORT".',
            ),
            $this->depends('puma') => 'bundle exec puma -b tcp://0.0.0.0:$PORT',
            default => 'bundle exec rackup --host 0.0.0.0 --port $PORT',
        };
    }

    private function depends(string $gem): bool
    {
        return $this->files->mention(self::GEM_FILES, $gem);
    }
}
