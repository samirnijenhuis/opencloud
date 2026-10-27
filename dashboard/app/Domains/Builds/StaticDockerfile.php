<?php

namespace App\Domains\Builds;

/** Serves the files of a repository with nginx on port 8080. With a build script, it serves what the build makes. */
final class StaticDockerfile
{
    /**
     * @param  NodeProject|null  $project  null when the repository has no package.json
     * @param  list<string>  $buildSecrets  the keys of the build-time env values
     */
    public static function render(?NodeProject $project, array $buildSecrets = []): string
    {
        $build = $project?->hasScript('build') === true;

        return DockerfileTemplate::render('static', ['build' => $build], [
            'node' => $build ? $project->nodeVersion() : '',
            'secret_mounts' => DockerfileTemplate::secretMounts($buildSecrets),
            'install' => $build ? $project->install() : '',
            'build' => $build ? $project->run('build') : '',
            'site' => $build ? '--from=build /site' : '.',
        ]);
    }
}
