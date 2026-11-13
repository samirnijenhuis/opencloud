<?php

namespace Tests\Domains\Environments\Jobs;

use App\Domains\Clusters\Models\Cluster;
use App\Domains\Environments\Jobs\DeleteLogLines;
use App\Domains\Environments\Models\Environment;
use App\Support\Kube\FakeKubectl;
use App\Support\Kube\KubectlException;
use App\Support\Kube\KubectlResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeleteLogLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_asks_loki_to_delete_the_lines_of_the_namespace_in_the_range(): void
    {
        $kubectl = FakeKubectl::install()->respond('exec', new KubectlResult(0, ''));

        DeleteLogLines::dispatchSync(Cluster::factory()->create(), 'oc-env-41', CarbonImmutable::parse('2026-10-01T08:00:00Z'), CarbonImmutable::parse('2026-10-29T10:00:00Z'));

        $this->assertSame([
            'exec', 'deploy/loki', '--namespace=opencloud', '--container=query', '--', 'wget', '-q', '-T', '20', '-O-', '--post-data=',
            'http://127.0.0.1:3100/loki/api/v1/delete?query=%7Bnamespace%3D%22oc-env-41%22%7D&start=2026-10-01T08%3A00%3A00%2B00%3A00&end=2026-10-29T10%3A00%3A00%2B00%3A00',
        ], $kubectl->calls[0]['args']);
    }

    public function test_a_deleted_environment_gets_a_delete_of_each_line_up_to_1_minute_from_now_that_waits_until_then(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $environment = Environment::factory()->create(['created_at' => '2026-10-01 08:00:00']);

        DeleteLogLines::dispatchForDeleted($environment);

        Queue::assertPushed(DeleteLogLines::class, function (DeleteLogLines $job) use ($environment) {
            $this->assertTrue($job->cluster->is($environment->application->cluster));
            $this->assertSame(
                ["oc-env-{$environment->id}", '2026-10-01T08:00:00+00:00', now()->addMinute()->toRfc3339String()],
                [$job->namespace, $job->from->toRfc3339String(), $job->to->toRfc3339String()],
            );
            $this->assertEquals(now()->addMinute(), $job->delay);

            return true;
        });
    }

    public function test_a_refused_delete_fails_the_try_so_the_queue_sends_it_again(): void
    {
        FakeKubectl::install()->respond('exec', new KubectlResult(1, '', 'wget: server returned error: HTTP/1.1 400 Bad Request'));

        $this->expectException(KubectlException::class);
        DeleteLogLines::dispatchSync(Cluster::factory()->create(), 'oc-env-41', now()->subHour(), now());
    }
}
