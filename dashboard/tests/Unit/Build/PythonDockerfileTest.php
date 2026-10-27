<?php

namespace Tests\Unit\Build;

use App\Domains\Builds\Exceptions\RepositoryDoesNotFit;
use App\Domains\Builds\PythonDockerfile;
use App\Domains\Builds\PythonProject;
use App\Domains\Builds\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PythonDockerfileTest extends TestCase
{
    private const DJANGO_MANAGE = <<<'PY'
        import os, sys

        if __name__ == "__main__":
            os.environ.setdefault("DJANGO_SETTINGS_MODULE", "mysite.settings")
            from django.core.management import execute_from_command_line
            execute_from_command_line(sys.argv)
        PY;

    public function test_a_django_app_with_uv_gets_the_python_of_its_pin_uv_sync_the_build_command_and_gunicorn(): void
    {
        $project = self::project([
            '.python-version' => "3.12\n",
            'pyproject.toml' => "[project]\nname = \"mysite\"\nrequires-python = \">=3.11\"\ndependencies = [\"django>=5.2\", \"gunicorn>=23\"]\n",
            'uv.lock' => "version = 1\n[[package]]\nname = \"django\"\n[[package]]\nname = \"gunicorn\"\n",
            'requirements.txt' => "flask\n",
            'manage.py' => self::DJANGO_MANAGE,
            'mysite/wsgi.py' => "application = get_wsgi_application()\n",
        ]);

        $this->assertSame(<<<'DOCKERFILE'
            FROM python:3.12
            # tini runs as PID 1 and passes SIGTERM to the app.
            RUN apt-get update && apt-get install -y --no-install-recommends tini && rm -rf /var/lib/apt/lists/*
            ENV PIP_DISABLE_PIP_VERSION_CHECK=1 PIP_ROOT_USER_ACTION=ignore
            RUN pip install uv
            # The app gets its own virtual env, so the dependencies of the tool above never mix with those of the app.
            RUN python -m venv /opt/venv
            ENV VIRTUAL_ENV=/opt/venv PATH=/opt/venv/bin:$PATH UV_PROJECT_ENVIRONMENT=/opt/venv UV_PYTHON_DOWNLOADS=never UV_LINK_MODE=copy
            # Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
            ARG OPENCLOUD_BUILD_ENV
            WORKDIR /app
            COPY . .
            RUN --mount=type=cache,target=/root/.cache --mount=type=secret,id=PIP_INDEX_URL,env=PIP_INDEX_URL uv sync --locked --no-dev
            RUN --mount=type=secret,id=PIP_INDEX_URL,env=PIP_INDEX_URL python manage.py collectstatic --noinput
            ENTRYPOINT ["/usr/bin/tini", "--"]
            CMD ["sh", "-c", "exec gunicorn mysite.wsgi --bind 0.0.0.0:$PORT"]

            DOCKERFILE, PythonDockerfile::render($project, ['PIP_INDEX_URL'], ' python manage.py collectstatic --noinput '));
    }

    /** @return array<string, array{array<string, string>, list<string>}> */
    public static function dependencyFiles(): array
    {
        $procfile = ['Procfile' => "web: gunicorn app:app\n"];
        $install = 'RUN --mount=type=cache,target=/root/.cache ';

        return [
            'poetry.lock beats Pipfile.lock and requirements.txt' => [
                ['poetry.lock' => '', 'Pipfile.lock' => '{}', 'requirements.txt' => '', ...$procfile],
                ['RUN pip install poetry', $install.'poetry install --only main --no-root --no-interaction'],
            ],
            'Pipfile.lock beats requirements.txt' => [
                ['Pipfile.lock' => '{}', 'requirements.txt' => '', ...$procfile],
                ['RUN pip install pipenv', $install.'pipenv install --deploy'],
            ],
            'requirements.txt beats pyproject.toml and needs no tool' => [
                ['requirements.txt' => "flask\n", 'pyproject.toml' => "[project]\n", ...$procfile],
                [$install.'pip install -r requirements.txt'],
            ],
            'pyproject.toml alone' => [
                ['pyproject.toml' => "[project]\ndependencies = [\"flask\"]\n", ...$procfile],
                ['RUN pip install uv', $install.'uv pip install -r pyproject.toml'],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $expected  the RUN lines that install the tool and the dependencies
     */
    #[DataProvider('dependencyFiles')]
    public function test_the_first_dependency_file_picks_the_tool_and_the_install_command(array $files, array $expected): void
    {
        $dockerfile = PythonDockerfile::render(self::project($files));

        $this->assertSame($expected, array_values(preg_grep('/^RUN (pip install (uv|poetry|pipenv)$|--mount)/', explode("\n", $dockerfile))));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function versions(): array
    {
        $pyproject = fn (string $range) => ['pyproject.toml' => "[project]\nname = \"api\"\nrequires-python = \"{$range}\"\n"];

        return [
            'no pin and no range' => [['requirements.txt' => ''], '3.14'],
            'a full version in .python-version is exact' => [['.python-version' => "3.11.9\n", ...$pyproject('>=3.13')], '3.11.9'],
            'a comment line in .python-version' => [['.python-version' => "# pyenv\n3.13\n", 'requirements.txt' => ''], '3.13'],
            'the python_version of the Pipfile wins over requires-python' => [['Pipfile.lock' => '{}', 'Pipfile' => "[requires]\npython_version = \"3.12\"\n", ...$pyproject('>=3.13')], '3.12'],
            'the python_full_version of the Pipfile is exact' => [['Pipfile.lock' => '{}', 'Pipfile' => "[requires]\npython_full_version = \"3.12.4\"\n"], '3.12.4'],
            'a range that allows the default' => [$pyproject('>=3.10'), '3.14'],
            'a range below the default gets its highest version' => [$pyproject('>=3.10,<3.13'), '3.12'],
            'a compatible release' => [$pyproject('~= 3.15.0'), '3.15'],
            'a wildcard' => [$pyproject('==3.11.*'), '3.11'],
            'a range that Composer cannot parse gets the default' => [$pyproject('>=3.8,!=3.9.*'), '3.14'],
        ];
    }

    /** @param  array<string, string>  $files */
    #[DataProvider('versions')]
    public function test_the_pin_or_the_range_picks_the_python_image(array $files, string $expected): void
    {
        $dockerfile = PythonDockerfile::render(self::project(['Procfile' => "web: python serve.py\n", ...$files]));

        $this->assertSame("FROM python:{$expected}", strtok($dockerfile, "\n"));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function startCommands(): array
    {
        $flask = "from flask import Flask\n\nserver = Flask(__name__)\n";
        $fastapi = "from fastapi import FastAPI\napp: FastAPI\napp = FastAPI()\n";

        return [
            'the web line of the Procfile wins over Django' => [
                ['Procfile' => "release: python manage.py migrate\nweb: gunicorn mysite.wsgi --workers 3 --bind 0.0.0.0:\$PORT\r\nworker: celery -A mysite worker\n", 'requirements.txt' => "gunicorn\n", 'manage.py' => self::DJANGO_MANAGE, 'mysite/wsgi.py' => ''],
                'CMD ["sh", "-c", "exec gunicorn mysite.wsgi --workers 3 --bind 0.0.0.0:$PORT"]',
            ],
            'a Procfile line with a list runs without exec' => [
                ['Procfile' => "web: python manage.py check && gunicorn mysite.wsgi\n", 'requirements.txt' => ''],
                'CMD ["sh", "-c", "python manage.py check && gunicorn mysite.wsgi"]',
            ],
            'django with gunicorn and a settings package of more than one level' => [
                ['requirements.txt' => "Django==5.2\ngunicorn==23.0\n", 'manage.py' => str_replace('mysite.settings', 'config.settings.production', self::DJANGO_MANAGE), 'config/wsgi.py' => '', 'config/asgi.py' => ''],
                'CMD ["sh", "-c", "exec gunicorn config.wsgi --bind 0.0.0.0:$PORT"]',
            ],
            'django with only uvicorn uses the asgi module' => [
                ['Pipfile.lock' => '{"default": {"django": {}, "uvicorn": {}}}', 'manage.py' => self::DJANGO_MANAGE, 'mysite/wsgi.py' => '', 'mysite/asgi.py' => ''],
                'CMD ["sh", "-c", "exec uvicorn mysite.asgi:application --host 0.0.0.0 --port $PORT"]',
            ],
            'flask with gunicorn in app.py' => [
                ['requirements.txt' => "Flask>=3\ngunicorn\n", 'app.py' => $flask],
                'CMD ["sh", "-c", "exec gunicorn app:server --bind 0.0.0.0:$PORT"]',
            ],
            'fastapi in a package with uvicorn from the lock file' => [
                ['poetry.lock' => "[[package]]\nname = \"uvicorn\"\n", 'pyproject.toml' => '', 'app/main.py' => $fastapi],
                'CMD ["sh", "-c", "exec uvicorn app.main:app --host 0.0.0.0 --port $PORT"]',
            ],
            'fastapi[standard] brings uvicorn' => [
                ['requirements.txt' => "fastapi[standard]>=0.115\n", 'main.py' => $fastapi],
                'CMD ["sh", "-c", "exec uvicorn main:app --host 0.0.0.0 --port $PORT"]',
            ],
        ];
    }

    /** @param  array<string, string>  $files */
    #[DataProvider('startCommands')]
    public function test_the_procfile_or_the_framework_picks_the_start_command(array $files, string $expected): void
    {
        $dockerfile = PythonDockerfile::render(self::project($files));

        $this->assertSame($expected, array_values(preg_grep('/^CMD /', explode("\n", $dockerfile)))[0]);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function projectsThatCannotBuild(): array
    {
        $noStart = 'The repository has no Procfile with a web line, and opencloud found no Django, Flask, or FastAPI app with gunicorn or uvicorn in its dependencies, so it does not know how to start the app. Add a Procfile with a web line, for example "web: gunicorn app:app --bind 0.0.0.0:$PORT". To use another preset, create a new application with the same repository.';

        return [
            'django without a server' => [['requirements.txt' => "django\n", 'manage.py' => self::DJANGO_MANAGE, 'mysite/wsgi.py' => ''], $noStart],
            'flask with only uvicorn-worker' => [['requirements.txt' => "flask\nuvicorn-worker\n", 'app.py' => "app = Flask(__name__)\n"], $noStart],
            'a Procfile without a web line' => [['requirements.txt' => "gunicorn\n", 'Procfile' => "worker: python worker.py\n"], $noStart],
            'a python version without an image' => [
                ['.python-version' => "3.8.18\n", 'Procfile' => "web: python serve.py\n", 'requirements.txt' => ''],
                '.python-version requires Python 3.8.18. Opencloud builds with Python 3.10, 3.11, 3.12, 3.13, 3.14, 3.15.',
            ],
        ];
    }

    /** @param  array<string, string>  $files */
    #[DataProvider('projectsThatCannotBuild')]
    public function test_a_project_that_opencloud_cannot_build_fails_with_a_plain_message(array $files, string $message): void
    {
        try {
            PythonDockerfile::render(self::project($files));
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(str_ends_with($message, 'same repository.'), $e instanceof RepositoryDoesNotFit);

            return;
        }

        $this->fail('The render did not fail.');
    }

    public function test_a_repository_without_a_dependency_file_is_no_python_project(): void
    {
        $this->assertNull(PythonProject::in(new RepositoryFiles(fn (string $path) => $path === 'app.py' ? '' : null)));
    }

    /** @param  array<string, string>  $files */
    private static function project(array $files): PythonProject
    {
        return PythonProject::in(new RepositoryFiles(fn (string $path) => $files[$path] ?? null));
    }
}
