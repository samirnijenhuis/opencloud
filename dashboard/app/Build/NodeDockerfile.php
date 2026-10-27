<?php

namespace App\Build;

use RuntimeException;

/** Turns the package.json of a Node.js app into Dockerfile text. The app listens on PORT, which opencloud sets. */
final class NodeDockerfile
{
    /**
     * @param  list<string>  $buildSecrets  the keys of the build-time env values
     *
     * @throws RuntimeException when package.json does not say how to start the app, or asks for an unknown Node
     */
    public static function render(NodeProject $project, array $buildSecrets = [], ?string $buildCommand = null): string
    {
        $mounts = DockerfileTemplate::secretMounts($buildSecrets);
        $buildCommand = trim((string) $buildCommand);

        return DockerfileTemplate::render('node', [], [
            'node' => $project->nodeVersion(),
            'secret_mounts' => $mounts,
            'install' => $project->install(),
            'build_script' => $project->hasScript('build') ? "RUN {$mounts}{$project->run('build')}" : '',
            'build_command' => $buildCommand === '' ? '' : "RUN {$mounts}{$buildCommand}",
            'start' => DockerfileTemplate::execForm($project->start()),
        ]);
    }
}
