---
paths:
  - "dashboard/tests/**"
  - "dashboard/database/factories/**"
  - "dashboard/phpunit.xml"
---

# Testing

## What a test proves

- Write the tests that prove the behavior that you changed. Do not write one test for each case that you can imagine.
- A test fails when you revert the change that it covers. Check this once before you commit.
- Set each precondition that the assertion depends on. Make sure that the test reaches the branch that its name describes.
- Never add an assertion only to silence a "no assertions" warning, for example `assertTrue(true)`.
- Never change production code to fit the setup of a test. Fix the setup.
- A known bug gets a failing test, not a changed expectation. For a bug fix, first write the test that shows the bug, and watch it fail.
- Assert the outcome, not only the status code. A test of a write endpoint asserts the changed rows (`assertDatabaseHas()`, `Domain::sole()`), the queued job (`Queue::assertPushedOn('builds', RunDeployment::class)`), or the kubectl calls (`$kubectl->assertRan('apply ')`).
- A test of a refused request (403) also asserts that nothing changed: the row count and `Queue::assertNothingPushed()`. `DomainControllerTest` does both.
- Compare with literal expected values. A test of a pure class (`CaddyConfig`, `EnvironmentManifests`, `RuntimeVersion`) compares its output with a literal array or string.
- Put each test in the test class of the class that owns the logic. Do not test the same logic in two places.

## Where a test goes

- The test tree mirrors `app/`. The test of `app/Domains/Clusters/Actions/ConnectCluster.php` is `tests/Domains/Clusters/Actions/ConnectClusterTest.php`. The same holds for `tests/Http`, `tests/Console`, and `tests/Support`. `tests/Database` mirrors `database/`, for example `tests/Database/Migrations` for `database/migrations`.
- Name the test class after the class under test: `RunDeploymentTest` for `RunDeployment`.
- `tests/Fakes` holds the fakes. `tests/Fixtures` holds the golden env file and a local OpenTofu module. `tests/Concerns` holds the shared test traits `BuildsEnvironments` and `WithGitHubApp`. `tests/Database` holds the migration, seeder, and SQLite tests.
- Name each test method `test_` and a sentence in snake_case that states the behavior: `test_a_developer_sees_the_domains_but_cannot_change_them`.
- Use `#[DataProvider]` for a table of cases, as `RoleTest` and `DeploymentStatusTest` do.

## The test environment

- The tests run on SQLite in memory: `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. A test class that uses the database uses `RefreshDatabase`.
- `phpunit.xml` sets `QUEUE_CONNECTION=sync`. A dispatched job runs at once in the test, unless the test calls `Queue::fake()` first.
- `phpunit.xml` sets `CACHE_STORE=array`, so no cached value lives on from another test.
- `TestCase` turns off Vite and points Inertia at `resources/js/pages`. `assertInertia()` with `->component('environments/show')` needs that page file.
- Change a setting in a test with `config(['opencloud.data_path' => $path])`. Do not set an env value at run time.

## Fakes

Never run a real binary or call the network in a test. Pick the fake by the tool that the code under test runs:

- **`kubectl`.** Call `FakeKubectl::install()`. It binds itself as `KubectlFactory`, records each call, and answers each call with `respond($prefix, $result)`. Assert with `assertRan()`, `callsStartingWith()`, and `appliedManifests()`. Without it, the test runs the real `kubectl`. `Process::fake()` does not stop that, because `Kubectl` runs Symfony Process directly.
- **`git`, `buildctl`, and `crane`.** Call `FakeProcesses::install()`. It fakes `Process`, records each command, and writes `$repository` into the checkout. `withLaravelRepository()` gives a small Laravel repository.
- **`tofu`.** Call `FakeTofu::install()`. It records each call with the variables file that the call found, returns `$outputs`, and fails the subcommand in `$failOn`.
- **HTTP APIs.** Call `Http::fake()` for GitHub, Cloudflare, DigitalOcean, Hetzner, AWS, and the release check. `WithGitHubApp::fakeGitHub()` fakes the GitHub App calls.
- **Queued work.** Call `Queue::fake()` to assert that an Action dispatches a job without running the job.

`FakeProcesses` and `FakeTofu` both call `Process::fake(['*' => ...])`, so the second call replaces the first. Use one of them in a test.

## Mocks

- Mock a collaborator of the class under test, for example an Action or a service such as `DnsResolver`. Bind the mock in the container with `$this->mock(AddDomain::class)->shouldReceive('execute')->once()` or `$this->app->instance(DnsResolver::class, $fake)`. A mock that is not in the container tests nothing.
- Never mock an Eloquent model. Create it with its factory.

## Factories

- Each model binds its factory with `#[UseFactory(XFactory::class)]`. The factories live in `database/factories`.
- A factory default gives a valid record. A test sets the state that it needs with a named state or with explicit attributes.
- Add a named state for a state that many tests need: `User::factory()->owner()`, `admin()`, and `unverified()`.
- Create a related record with its factory: `Domain::factory()->for($environment)`. Never use `X::query()->inRandomOrder()`.
- Never weaken a shared factory to make a failing test pass.

## What gets a test

- Test an enum method that holds a rule. `RoleTest` covers each pair of role and ability, and `DeploymentStatusTest` covers each move. Do not test the cases or the values of an enum alone.
- Test a migration that moves data. Insert rows in the old shape, run `up()`, and assert the new shape. Test `down()` the same way, as `MoveWorkerAndSchedulerIntoProcessesTest` does. A migration that only changes the schema gets no test.
- Test each secret column through `DataModelTest::encryptedFields()`.
- Test the console commands in `app/Console` like other code.
- Do not test Laravel itself, a service provider, Docker, or the CI config.
- Never delete a test during a refactor. Move it, or write a test that proves the same thing.
- `tests/e2e/` and the `verify-opencloud` skill prove the whole system on real machines. They do not replace the PHPUnit tests.

## The Architecture suite

- `tests/Architecture` checks the folder, the name, and the dependencies of each class. Each failure message names the rule file with the fix.
- `tests/Architecture/baseline.json` holds the old violations, as a count for each rule and file. A file with more violations than its count fails. A file with fewer violations also fails, until you lower its count.
- Never add an entry to the baseline or raise a count. Fix the code.
- When you fix an old violation, update the baseline in the same commit with `ARCHITECTURE_BASELINE=update vendor/bin/phpunit --testsuite=Architecture`. The command writes the whole file again. Run it only when the suite reports a fixed entry and no new violation.
