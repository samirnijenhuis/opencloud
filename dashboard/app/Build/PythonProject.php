<?php

namespace App\Build;

use RuntimeException;

/**
 * The dependency files, the Python version, and the start command of a Python app. The dependency file picks the tool
 * that installs the dependencies.
 */
final readonly class PythonProject
{
    /** The minors that have an official image. */
    public const PYTHON_VERSIONS = ['3.10', '3.11', '3.12', '3.13', '3.14', '3.15'];

    /** The newest Python with a year of fixes and wheels. */
    public const DEFAULT_PYTHON = '3.14';

    /** When a repository has more than one, the first one wins. */
    public const DEPENDENCY_FILES = ['uv.lock', 'poetry.lock', 'Pipfile.lock', 'requirements.txt', 'pyproject.toml'];

    /** A Flask or FastAPI app is the first "<name> = Flask(" or "<name> = FastAPI(" in these files. */
    public const APP_FILES = ['app.py', 'main.py', 'wsgi.py', 'asgi.py', 'app/__init__.py', 'app/main.py'];

    private function __construct(private RepositoryFiles $files, private string $dependencyFile) {}

    public static function in(RepositoryFiles $files): ?self
    {
        foreach (self::DEPENDENCY_FILES as $file) {
            if ($files->has($file)) {
                return new self($files, $file);
            }
        }

        return null;
    }

    /**
     * The first of .python-version, the python_version of the Pipfile, and requires-python in pyproject.toml. Pipenv
     * refuses to install with another Python than its Pipfile names.
     *
     * @throws RuntimeException when the project asks for a Python without an image
     */
    public function pythonVersion(): string
    {
        $sources = [
            '.python-version' => '/^\s*([^#\s]\S*)/m',
            'Pipfile' => '/^\s*python_(?:full_)?version\s*=\s*["\']([^"\']+)["\']/m',
            'pyproject.toml' => '/^\s*requires-python\s*=\s*["\']([^"\']+)["\']/m',
        ];

        foreach ($sources as $file => $pattern) {
            if (preg_match($pattern, (string) $this->files->read($file), $match) === 1) {
                return RuntimeVersion::tag('Python', $file, $match[1], self::PYTHON_VERSIONS, self::DEFAULT_PYTHON);
            }
        }

        return self::DEFAULT_PYTHON;
    }

    /** The shell command that installs the tool before the build values exist, or an empty string for pip. */
    public function tool(): string
    {
        return match ($this->dependencyFile) {
            'uv.lock', 'pyproject.toml' => 'pip install uv',
            'poetry.lock' => 'pip install poetry',
            'Pipfile.lock' => 'pip install pipenv',
            'requirements.txt' => '',
        };
    }

    /** The shell command that installs the dependencies into the active virtual env, without the dev dependencies. */
    public function install(): string
    {
        return match ($this->dependencyFile) {
            'uv.lock' => 'uv sync --locked --no-dev',
            'poetry.lock' => 'poetry install --only main --no-root --no-interaction',
            'Pipfile.lock' => 'pipenv install --deploy',
            'requirements.txt' => 'pip install -r requirements.txt',
            'pyproject.toml' => 'uv pip install -r pyproject.toml',
        };
    }

    /**
     * The web line of the Procfile, else a Django app with gunicorn or uvicorn, else a Flask app with gunicorn or a
     * FastAPI app with uvicorn. The server listens on 0.0.0.0 and on PORT.
     *
     * @throws RepositoryDoesNotFit when none of these fits
     */
    public function start(): string
    {
        return $this->files->procfileWeb() ?? $this->django() ?? $this->flaskOrFastApi() ?? throw new RepositoryDoesNotFit(
            'The repository has no Procfile with a web line, and opencloud found no Django, Flask, or FastAPI app with gunicorn or uvicorn in its dependencies, so it does not know how to start the app. Add a Procfile with a web line, for example "web: gunicorn app:app --bind 0.0.0.0:$PORT".',
        );
    }

    /** manage.py names the settings module, and its package holds wsgi.py and asgi.py. */
    private function django(): ?string
    {
        if (preg_match('/DJANGO_SETTINGS_MODULE["\']\s*,\s*["\']([A-Za-z_]\w*)[.\w]*["\']/', (string) $this->files->read('manage.py'), $match) !== 1) {
            return null;
        }

        $package = $match[1];

        return match (true) {
            $this->depends('gunicorn') && $this->files->has("{$package}/wsgi.py") => "gunicorn {$package}.wsgi --bind 0.0.0.0:\$PORT",
            $this->depends('uvicorn') && $this->files->has("{$package}/asgi.py") => "uvicorn {$package}.asgi:application --host 0.0.0.0 --port \$PORT",
            default => null,
        };
    }

    private function flaskOrFastApi(): ?string
    {
        foreach (self::APP_FILES as $path) {
            if (preg_match('/^(\w+)\s*=\s*(Flask|FastAPI)\(/m', (string) $this->files->read($path), $match) !== 1) {
                continue;
            }

            $module = str_replace('/', '.', preg_replace('/(\/__init__)?\.py$/', '', $path));

            return match (true) {
                $match[2] === 'Flask' && $this->depends('gunicorn') => "gunicorn {$module}:{$match[1]} --bind 0.0.0.0:\$PORT",
                // fastapi[standard] brings uvicorn.
                $match[2] === 'FastAPI' && ($this->depends('uvicorn') || $this->depends('fastapi[standard]')) => "uvicorn {$module}:{$match[1]} --host 0.0.0.0 --port \$PORT",
                default => null,
            };
        }

        return null;
    }

    private function depends(string $package): bool
    {
        return $this->files->mention([...self::DEPENDENCY_FILES, 'Pipfile'], $package);
    }
}
