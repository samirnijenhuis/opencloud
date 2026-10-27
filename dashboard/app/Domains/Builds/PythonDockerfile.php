<?php

namespace App\Domains\Builds;

use App\Domains\Deployments\EnvironmentManifests;
use RuntimeException;

/** Turns a Python app into Dockerfile text. The app listens on PORT, which opencloud sets. */
final class PythonDockerfile
{
    /**
     * @param  list<string>  $buildSecrets  the keys of the build-time env values
     *
     * @throws RuntimeException when opencloud does not know how to start the app, or the app asks for an unknown Python
     */
    public static function render(PythonProject $project, array $buildSecrets = [], ?string $buildCommand = null): string
    {
        $mounts = DockerfileTemplate::secretMounts($buildSecrets);
        $tool = $project->tool();

        return DockerfileTemplate::render('python', [], [
            'python' => $project->pythonVersion(),
            'tool' => $tool === '' ? '' : "RUN {$tool}",
            'secret_mounts' => $mounts,
            'install' => $project->install(),
            'build_command' => DockerfileTemplate::run($buildCommand, $mounts),
            'start' => DockerfileTemplate::execForm(EnvironmentManifests::shellCommand($project->start())),
        ]);
    }
}
