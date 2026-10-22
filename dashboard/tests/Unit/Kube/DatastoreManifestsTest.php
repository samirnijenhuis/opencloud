<?php

namespace Tests\Unit\Kube;

use App\Enums\DatastoreKind;
use App\Kube\Manifests\DatastoreManifests;
use App\Models\BackupTarget;
use App\Models\Datastore;
use App\Models\DatastoreAttachment;
use Tests\TestCase;

class DatastoreManifestsTest extends TestCase
{
    private const VOLUME_CLAIMS = [[
        'metadata' => ['name' => 'data'],
        'spec' => ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => '10Gi']]],
    ]];

    public function test_mysql_is_a_secret_a_service_and_a_statefulset_with_a_volume(): void
    {
        $labels = ['opencloud.dev/datastore' => '5'];

        $this->assertSame([
            [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => 'mysql-5', 'namespace' => 'oc-datastores', 'labels' => $labels],
                'type' => 'Opaque',
                'data' => ['MYSQL_ROOT_PASSWORD' => 'cm9vdC1wdw=='],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => ['name' => 'mysql-5', 'namespace' => 'oc-datastores', 'labels' => $labels],
                'spec' => ['selector' => $labels, 'ports' => [['name' => 'mysql', 'port' => 3306, 'targetPort' => 'mysql']]],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'StatefulSet',
                'metadata' => ['name' => 'mysql-5', 'namespace' => 'oc-datastores', 'labels' => $labels],
                'spec' => [
                    'serviceName' => 'mysql-5',
                    'replicas' => 1,
                    'selector' => ['matchLabels' => $labels],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => [
                            'containers' => [[
                                'name' => 'mysql',
                                'image' => 'mysql:8.4',
                                'envFrom' => [['secretRef' => ['name' => 'mysql-5']]],
                                'ports' => [['name' => 'mysql', 'containerPort' => 3306]],
                                'readinessProbe' => ['tcpSocket' => ['port' => 'mysql'], 'periodSeconds' => 5],
                                'volumeMounts' => [['name' => 'data', 'mountPath' => '/var/lib/mysql']],
                            ]],
                        ],
                    ],
                    'volumeClaimTemplates' => self::VOLUME_CLAIMS,
                ],
            ],
        ], DatastoreManifests::mysql($this->datastore(DatastoreKind::Mysql, '8.4')));
    }

    public function test_postgres_keeps_its_data_in_a_subfolder_of_the_volume(): void
    {
        [$secret, $service, $statefulSet] = DatastoreManifests::postgres($this->datastore(DatastoreKind::Postgres, '17'));

        $this->assertSame(['name' => 'postgres-5', 'namespace' => 'oc-datastores', 'labels' => ['opencloud.dev/datastore' => '5']], $secret['metadata']);
        $this->assertSame(['POSTGRES_PASSWORD' => 'cm9vdC1wdw=='], $secret['data']);
        $this->assertSame([['name' => 'postgres', 'port' => 5432, 'targetPort' => 'postgres']], $service['spec']['ports']);
        $this->assertSame([
            'containers' => [[
                'name' => 'postgres',
                'image' => 'postgres:17',
                'env' => [['name' => 'PGDATA', 'value' => '/var/lib/postgresql/data/pgdata']],
                'envFrom' => [['secretRef' => ['name' => 'postgres-5']]],
                'ports' => [['name' => 'postgres', 'containerPort' => 5432]],
                'readinessProbe' => ['tcpSocket' => ['port' => 'postgres'], 'periodSeconds' => 5],
                'volumeMounts' => [['name' => 'data', 'mountPath' => '/var/lib/postgresql/data']],
            ]],
        ], $statefulSet['spec']['template']['spec']);
        $this->assertSame(self::VOLUME_CLAIMS, $statefulSet['spec']['volumeClaimTemplates']);
    }

    public function test_rustfs_runs_the_server_and_the_rc_admin_client_with_the_root_credentials(): void
    {
        [$secret, $service, $statefulSet] = DatastoreManifests::rustfs($this->datastore(DatastoreKind::Rustfs, '1.0.3'));

        $this->assertSame(['RUSTFS_ACCESS_KEY' => 'b3BlbmNsb3Vk', 'RUSTFS_SECRET_KEY' => 'cm9vdC1wdw=='], $secret['data']);
        $this->assertSame('rustfs-5', $service['metadata']['name']);
        $this->assertSame([['name' => 's3', 'port' => 9000, 'targetPort' => 's3']], $service['spec']['ports']);
        $this->assertSame([
            'securityContext' => ['fsGroup' => 10001],
            'containers' => [
                [
                    'name' => 'rustfs',
                    'image' => 'rustfs/rustfs:1.0.3',
                    'env' => [
                        ['name' => 'RUSTFS_VOLUMES', 'value' => '/data'],
                        ['name' => 'RUSTFS_ADDRESS', 'value' => ':9000'],
                        ['name' => 'RUSTFS_CONSOLE_ENABLE', 'value' => 'false'],
                    ],
                    'envFrom' => [['secretRef' => ['name' => 'rustfs-5']]],
                    'ports' => [['name' => 's3', 'containerPort' => 9000]],
                    'readinessProbe' => ['tcpSocket' => ['port' => 's3'], 'periodSeconds' => 5],
                    'volumeMounts' => [['name' => 'data', 'mountPath' => '/data']],
                ],
                [
                    'name' => 'rc',
                    'image' => 'rustfs/rc:v0.1.36',
                    'command' => ['sleep', '2147483647'],
                    'envFrom' => [['secretRef' => ['name' => 'rustfs-5']]],
                ],
            ],
        ], $statefulSet['spec']['template']['spec']);
        $this->assertSame(self::VOLUME_CLAIMS, $statefulSet['spec']['volumeClaimTemplates']);
    }

    public function test_valkey_is_one_small_server_per_attachment_with_a_password_and_an_append_only_file(): void
    {
        $attachment = new DatastoreAttachment(['datastore_id' => 6, 'environment_id' => 7, 'password' => 'valkey-pw']);
        $attachment->id = 9;
        $attachment->setRelation('datastore', $this->datastore(DatastoreKind::Valkey, '9.1', id: 6));
        $labels = ['opencloud.dev/datastore' => '6', 'opencloud.dev/attachment' => '9', 'opencloud.dev/environment' => '7'];
        $metadata = ['name' => 'valkey-9', 'namespace' => 'oc-datastores', 'labels' => $labels];

        $this->assertSame([
            ['apiVersion' => 'v1', 'kind' => 'Secret', 'metadata' => $metadata, 'type' => 'Opaque', 'data' => ['VALKEY_PASSWORD' => 'dmFsa2V5LXB3']],
            [
                'apiVersion' => 'v1',
                'kind' => 'PersistentVolumeClaim',
                'metadata' => $metadata,
                'spec' => ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => '1Gi']]],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => $metadata,
                'spec' => ['selector' => $labels, 'ports' => [['name' => 'valkey', 'port' => 6379, 'targetPort' => 'valkey']]],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => $metadata,
                'spec' => [
                    'replicas' => 1,
                    'selector' => ['matchLabels' => $labels],
                    'strategy' => ['type' => 'Recreate'],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => [
                            'containers' => [[
                                'name' => 'valkey',
                                'image' => 'valkey/valkey:9.1',
                                'args' => ['valkey-server', '--requirepass', '$(VALKEY_PASSWORD)', '--appendonly', 'yes', '--dir', '/data'],
                                'env' => [['name' => 'VALKEY_PASSWORD', 'valueFrom' => ['secretKeyRef' => ['name' => 'valkey-9', 'key' => 'VALKEY_PASSWORD']]]],
                                'ports' => [['name' => 'valkey', 'containerPort' => 6379]],
                                'readinessProbe' => ['tcpSocket' => ['port' => 'valkey'], 'periodSeconds' => 5],
                                'volumeMounts' => [['name' => 'data', 'mountPath' => '/data']],
                            ]],
                            'volumes' => [['name' => 'data', 'persistentVolumeClaim' => ['claimName' => 'valkey-9']]],
                        ],
                    ],
                ],
            ],
        ], DatastoreManifests::valkey($attachment));
    }

    public function test_the_bucket_copy_job_syncs_every_bucket_to_a_folder_of_the_backup_target(): void
    {
        $target = new BackupTarget(['endpoint' => 'https://s3.example.com', 'bucket' => 'backups', 'access_key' => 'AK', 'secret_key' => 'SK']);

        [$secret, $job] = DatastoreManifests::copyBuckets($this->datastore(DatastoreKind::Rustfs, '1.0.3'), $target, '20261023');

        $this->assertSame(['name' => 'backup-target', 'namespace' => 'oc-datastores'], $secret['metadata']);
        $this->assertSame(['ACCESS_KEY' => 'QUs=', 'SECRET_KEY' => 'U0s='], $secret['data']);
        $this->assertSame('rustfs-5-copy-20261023', $job['metadata']['name']);
        $container = $job['spec']['template']['spec']['containers'][0];
        $this->assertSame(['rclone/rclone:1.75.2', ['sync', 'source:', 'target:backups/rustfs/5']], [$container['image'], $container['args']]);
        $this->assertSame([
            'RCLONE_CONFIG_SOURCE_TYPE' => 's3',
            'RCLONE_CONFIG_SOURCE_PROVIDER' => 'Other',
            'RCLONE_CONFIG_SOURCE_ENDPOINT' => 'http://rustfs-5.oc-datastores.svc.cluster.local:9000',
            'RCLONE_CONFIG_SOURCE_ACCESS_KEY_ID' => ['secretKeyRef' => ['name' => 'rustfs-5', 'key' => 'RUSTFS_ACCESS_KEY']],
            'RCLONE_CONFIG_SOURCE_SECRET_ACCESS_KEY' => ['secretKeyRef' => ['name' => 'rustfs-5', 'key' => 'RUSTFS_SECRET_KEY']],
            'RCLONE_CONFIG_TARGET_TYPE' => 's3',
            'RCLONE_CONFIG_TARGET_PROVIDER' => 'Other',
            'RCLONE_CONFIG_TARGET_ENDPOINT' => 'https://s3.example.com',
            'RCLONE_CONFIG_TARGET_ACCESS_KEY_ID' => ['secretKeyRef' => ['name' => 'backup-target', 'key' => 'ACCESS_KEY']],
            'RCLONE_CONFIG_TARGET_SECRET_ACCESS_KEY' => ['secretKeyRef' => ['name' => 'backup-target', 'key' => 'SECRET_KEY']],
            'RCLONE_CONFIG_TARGET_FORCE_PATH_STYLE' => 'true',
        ], collect($container['env'])->mapWithKeys(fn (array $env) => [$env['name'] => $env['value'] ?? $env['valueFrom']])->all());
    }

    private function datastore(DatastoreKind $kind, string $version, int $id = 5): Datastore
    {
        $datastore = new Datastore(['kind' => $kind, 'version' => $version, 'root_password' => 'root-pw']);
        $datastore->id = $id;

        return $datastore;
    }
}
