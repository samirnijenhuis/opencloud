<?php

namespace App\Clusters;

use App\Build\Builder;
use App\Deploy\DeploymentLog;
use App\Kube\Kubectl;
use App\Kube\Manifests\EnvironmentManifests;
use App\Models\Deployment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Puts a built image where the cluster of the deployment pulls from. Process::fake() swaps crane in tests.
 */
final class ImageCopy
{
    /**
     * The local cluster pulls the built image from the local registry. Another cluster gets a copy in its own registry
     * and a pull secret in the environment namespace.
     *
     * @return string the image that the pods of the deployment run
     *
     * @throws RuntimeException when crane cannot copy the image
     */
    public static function toCluster(Deployment $deployment, string $builtImage, DeploymentLog $log): string
    {
        $environment = $deployment->environment;
        $cluster = $environment->application->cluster;
        $registry = $cluster->registry();

        if ($registry === null) {
            return $builtImage;
        }

        $target = Builder::imageReference($deployment, $registry->address);
        $log->write("Copying the image to {$target}.");
        self::copy(Builder::imageReference($deployment, config('opencloud.registry_address')), $target, $registry);

        $manifests = new EnvironmentManifests($environment);
        Kubectl::for($cluster)->apply([$manifests->namespace(), $manifests->pullSecret($registry)]);

        return $target;
    }

    private static function copy(string $source, string $target, ClusterRegistry $registry): void
    {
        $dir = sys_get_temp_dir().'/opencloud-crane-'.Str::lower(Str::random(10));
        File::makeDirectory($dir, 0700);

        try {
            file_put_contents("{$dir}/config.json", json_encode($registry->dockerConfig(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $result = Process::env(['DOCKER_CONFIG' => $dir])
                ->timeout(1800)
                ->run(['crane', 'copy', ...($registry->insecure ? ['--insecure'] : []), $source, $target]);

            if ($result->failed()) {
                throw new RuntimeException('crane could not copy the image to the cluster registry. '.trim($result->errorOutput()));
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
