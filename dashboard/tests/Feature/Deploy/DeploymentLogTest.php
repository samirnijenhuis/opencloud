<?php

namespace Tests\Feature\Deploy;

use App\Domains\Deployments\DeploymentLog;
use App\Domains\Deployments\Models\Deployment;
use App\Support\SecretMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeploymentLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_colors_are_removed_from_log_lines(): void
    {
        $deployment = Deployment::factory()->create(['log' => null]);
        Carbon::setTestNow('2026-10-23T12:00:00Z');

        DeploymentLog::for($deployment)->write("  laravel/wayfinder \e[90m.\e[39m\e[90m.\e[39m \e[32;1mDONE\e[39;22m");

        $this->assertSame("2026-10-23T12:00:00Z   laravel/wayfinder .. DONE\n", $deployment->fresh()->log);
    }

    public function test_output_split_across_chunks_becomes_whole_masked_lines(): void
    {
        $deployment = Deployment::factory()->create();
        $log = new DeploymentLog($deployment, new SecretMasker(['hunter22']));

        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
        $log->write("first\r\nsecond\n");
        $log->chunk('#3 pass=hun');
        $log->chunk("ter22 ok\n#4 st");
        $this->travelTo(Carbon::parse('2026-10-23 12:00:05'));
        $log->chunk('ep');
        $log->flush();
        $log->flush();

        $this->assertSame(
            "2026-10-23T12:00:00Z first\n".
            "2026-10-23T12:00:00Z second\n".
            "2026-10-23T12:00:00Z #3 pass=******** ok\n".
            "2026-10-23T12:00:05Z #4 step\n",
            $deployment->fresh()->log,
        );
    }
}
