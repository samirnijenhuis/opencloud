<?php

namespace App\Domains\Builds;

use App\Domains\Deployments\EnvironmentManifests;
use RuntimeException;

/** Turns a Ruby app into Dockerfile text. The app listens on PORT, which opencloud sets. */
final class RubyDockerfile
{
    /**
     * @param  list<string>  $buildSecrets  the keys of the build-time env values
     *
     * @throws RuntimeException when opencloud does not know how to start the app, or the app asks for an unknown Ruby
     */
    public static function render(RubyProject $project, array $buildSecrets = [], ?string $buildCommand = null): string
    {
        $mounts = DockerfileTemplate::secretMounts($buildSecrets);
        $buildCommand = trim((string) $buildCommand);

        return DockerfileTemplate::render('ruby', [], [
            'ruby' => $project->rubyVersion(),
            'secret_mounts' => $mounts,
            // The precompile loads the app, and production refuses to load without a secret key base.
            'assets' => $project->hasAssets() ? "RUN {$mounts}SECRET_KEY_BASE_DUMMY=1 bin/rails assets:precompile" : '',
            'build_command' => $buildCommand === '' ? '' : "RUN {$mounts}{$buildCommand}",
            'start' => DockerfileTemplate::execForm(EnvironmentManifests::shellCommand($project->start())),
        ]);
    }
}
