"""Reports what the opencloud verification matrix checks: env values, the build, the databases, and the cache."""
import hashlib
import os
from pathlib import Path
from urllib.parse import unquote, urlsplit

import psycopg
import pymysql
import redis

# The generic env profile writes mysql:// and postgresql:// URLs.
DRIVERS = {'mysql': 'mysql', 'postgres': 'pgsql', 'postgresql': 'pgsql'}
ID = {'mysql': 'INT AUTO_INCREMENT PRIMARY KEY', 'pgsql': 'SERIAL PRIMARY KEY'}


def env(key):
    return os.environ.get(key) or None


def connect(url):
    """Returns the driver and a connection in autocommit mode. Both drivers take %s placeholders."""
    parts = urlsplit(url)
    driver = DRIVERS.get(parts.scheme)
    if driver == 'mysql':
        connection = pymysql.connect(host=parts.hostname, port=parts.port or 3306, user=unquote(parts.username or ''), password=unquote(parts.password or ''),
                                     database=parts.path.lstrip('/'), connect_timeout=5, autocommit=True)
        return driver, connection
    if driver == 'pgsql':
        return driver, psycopg.connect(url, connect_timeout=5, autocommit=True)
    raise ValueError(f'{parts.scheme}:// is not a mysql:// or postgresql:// URL.')


def query(connection, sql, params=None):
    with connection.cursor() as cursor:
        cursor.execute(sql, params)
        return cursor.fetchall() if cursor.description else []


def database():
    url = env('DATABASE_URL')
    if url is None:
        return None
    try:
        driver, connection = connect(url)
        with connection:
            query(connection, 'INSERT INTO verify_hits (created_at) VALUES (CURRENT_TIMESTAMP)')
            hits, deploys = (query(connection, f'SELECT COUNT(*) FROM {table}')[0][0] for table in ('verify_hits', 'verify_deploys'))
            return {'driver': driver, 'ok': True, 'hits': hits, 'deploys': deploys}
    except Exception as error:
        return {'driver': DRIVERS.get(urlsplit(url).scheme), 'ok': False, 'error': str(error)}


def extra():
    """Opencloud sets one <NAME>_DATABASE_URL value for each database that is not the default."""
    found = {}
    for key in sorted(k for k in os.environ if k.endswith('_DATABASE_URL')):
        name = key.removesuffix('_DATABASE_URL').lower()
        try:
            driver, connection = connect(os.environ[key])
            with connection:
                found[name] = {'driver': driver, 'ok': query(connection, 'SELECT 1')[0][0] == 1}
        except Exception as error:
            found[name] = {'driver': DRIVERS.get(urlsplit(os.environ[key]).scheme), 'ok': False, 'error': str(error)}
    return found


def cache():
    url = env('REDIS_URL')
    if url is None:
        return None
    try:
        with redis.Redis.from_url(url, socket_connect_timeout=5, socket_timeout=5) as client:
            return {'driver': 'redis', 'ok': client.ping() is True, 'hits': client.incr('verify:hits')}
    except Exception as error:
        return {'driver': 'redis', 'ok': False, 'error': str(error)}


def report():
    marker = Path(__file__).with_name('build-marker')
    secret = env('VERIFY_SECRET')
    return {
        'fixture': 'python',
        'marker': env('VERIFY_MARKER'),
        'build': (marker.read_text().strip() or None) if marker.exists() else None,
        'secret_sha': None if secret is None else hashlib.sha1(secret.encode()).hexdigest(),
        'db': database(),
        'cache': cache(),
        'extra': extra(),
    }
