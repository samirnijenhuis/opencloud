<?php

namespace Tests\Domains\Environments\Actions;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Environments\Actions\SaveEnvText;
use App\Domains\Environments\Actions\SetEnvVar;
use App\Domains\Environments\Enums\EnvVarAvailability;
use App\Domains\Environments\Enums\EnvVarSource;
use App\Domains\Environments\Models\Environment;
use App\Domains\Environments\Models\EnvVar;
use App\Domains\Members\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\BuildsEnvironments;
use Tests\TestCase;

class SaveEnvTextTest extends TestCase
{
    use BuildsEnvironments, RefreshDatabase;

    private Environment $environment;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = $this->makeEnvironment();
        $this->user = User::factory()->admin()->create();
    }

    /** @return array<string, string> */
    private function plain(): array
    {
        return $this->environment->envVars()->where('source', EnvVarSource::USER)->where('is_secret', false)->orderBy('key')->pluck('value', 'key')->all();
    }

    public function test_it_adds_changes_and_deletes_plain_values_and_audits_each_change(): void
    {
        EnvVar::factory()->for($this->environment)->create(['key' => 'KEEP', 'value' => 'same']);
        EnvVar::factory()->for($this->environment)->create(['key' => 'CHANGE', 'value' => 'old', 'available_at' => EnvVarAvailability::BOTH]);
        EnvVar::factory()->for($this->environment)->create(['key' => 'GONE', 'value' => 'x']);

        app(SaveEnvText::class)->execute($this->environment, $this->user, "# note\nKEEP=same\nexport CHANGE='new value'\nADDED=1\n");

        $this->assertSame(['ADDED' => '1', 'CHANGE' => 'new value', 'KEEP' => 'same'], $this->plain());
        $this->assertSame(EnvVarAvailability::BOTH, $this->environment->envVars()->where('key', 'CHANGE')->sole()->available_at);
        $this->assertSame(EnvVarAvailability::RUNTIME, $this->environment->envVars()->where('key', 'ADDED')->sole()->available_at);
        $this->assertEqualsCanonicalizing(
            ['env_var.created', 'env_var.updated', 'env_var.deleted'],
            AuditEvent::pluck('action')->all(),
        );
    }

    public function test_an_empty_text_deletes_every_plain_value_and_keeps_locked_ones(): void
    {
        EnvVar::factory()->for($this->environment)->create(['key' => 'A', 'value' => '1']);
        EnvVar::factory()->for($this->environment)->create(['key' => 'TOKEN', 'value' => 'secret-1', 'is_secret' => true]);
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_HOST', 'value' => 'db', 'source' => EnvVarSource::DATASTORE]);

        app(SaveEnvText::class)->execute($this->environment, $this->user, '');

        $this->assertSame([], $this->plain());
        $this->assertSame(['DB_HOST', 'TOKEN'], $this->environment->envVars()->orderBy('key')->pluck('key')->all());
        $this->assertSame('secret-1', $this->environment->envVars()->where('key', 'TOKEN')->value('value'));
    }

    public function test_a_locked_key_is_an_error_with_a_line_number_that_counts_the_locked_rows(): void
    {
        EnvVar::factory()->for($this->environment)->create(['key' => 'DB_HOST', 'value' => 'db', 'source' => EnvVarSource::DATASTORE]);
        EnvVar::factory()->for($this->environment)->create(['key' => 'TOKEN', 'value' => 'secret-1', 'is_secret' => true]);
        EnvVar::factory()->for($this->environment)->create(['key' => 'OLD', 'value' => '1']);

        try {
            app(SaveEnvText::class)->execute($this->environment, $this->user, "FINE=1\nDB_HOST=mine\nTOKEN=plain\nNOEQUALS");
            $this->fail('The save accepted locked keys.');
        } catch (ValidationException $e) {
            $this->assertSame(
                "Line 4 uses DB_HOST. A resource sets this key.\nLine 5 uses TOKEN. This key holds a secret. Replace it in the table.\nLine 6 has no = sign.",
                $e->errors()['text'][0],
            );
        }

        $this->assertSame(['OLD' => '1'], $this->plain());
        $this->assertSame('secret-1', $this->environment->envVars()->where('key', 'TOKEN')->value('value'));
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_failure_halfway_leaves_every_value_as_it_was(): void
    {
        EnvVar::factory()->for($this->environment)->create(['key' => 'A', 'value' => '1']);
        EnvVar::factory()->for($this->environment)->create(['key' => 'B', 'value' => '2']);
        $this->app->bind(SetEnvVar::class, fn () => new class extends SetEnvVar
        {
            private int $calls = 0;

            public function execute(Environment $environment, User $by, string $key, ?string $value, bool $isSecret, EnvVarAvailability $availableAt): EnvVar
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('The database stopped.');
                }

                return parent::execute($environment, $by, $key, $value, $isSecret, $availableAt);
            }
        });

        try {
            app(SaveEnvText::class)->execute($this->environment, $this->user, "A=10\nB=20\nC=30");
            $this->fail('The save did not fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame(['A' => '1', 'B' => '2'], $this->plain());
        $this->assertSame(0, AuditEvent::count());
    }
}
