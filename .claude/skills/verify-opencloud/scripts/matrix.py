#!/usr/bin/env python3
"""Drives the opencloud verification matrix on the machine that machine.sh made.

  matrix.py doctor                      read-only checks of the machine, the login, and the fixture branches
  matrix.py fixtures sync|check         push the fixture apps to their branches, or compare them
  matrix.py list [--set S] [--only P]   print the cells that a run would drive
  matrix.py run [--set S] [--only P]    drive the cells and write the evidence to .verify/runs/<run id>/
  matrix.py local [--set S] [--only P]  build and run the cells in Docker on this computer, with no machine
"""
import argparse
import fnmatch
import hashlib
import html
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.parse
from pathlib import Path

SKILL = Path(__file__).resolve().parent.parent
ROOT = Path(subprocess.check_output(['git', '-C', str(SKILL), 'rev-parse', '--show-toplevel'], text=True).strip())
STATE = ROOT / '.verify' / 'state'
RUNS = ROOT / '.verify' / 'runs'
MATRIX = json.loads((SKILL / 'matrix.json').read_text())
DRIVER = {'mysql': 'mysql', 'postgres': 'pgsql', 'mysqlpostgres': 'mysql', 'sqlite': 'sqlite'}
# The most specific condition wins when a fixture gives a value for more than one.
PRIORITY = ['valkey', 'sqlite', 'server-sql', 'sql', 'no-sql', 'always']
# The checks of the verify endpoint. The "checks" of a fixture list the ones that it reports. The runner skips the others.
CHECKS = {'marker', 'build', 'secret', 'db', 'extra', 'cache'}
REPORTS_URL = {'laravel': 'REPORTS_DB_URL', 'generic': 'REPORTS_DATABASE_URL'}


def repository_url():
    return f"https://github.com/{MATRIX['fixture_repository']}.git"


def machine_env():
    path = STATE / 'machine.env'
    if not path.exists():
        sys.exit(f'{path} is missing. Run scripts/machine.sh up first.')
    return dict(line.split('=', 1) for line in path.read_text().splitlines() if '=' in line)


class Cell:
    def __init__(self, cell_id):
        self.id = cell_id
        self.fixture_name, self.db, self.cache = cell_id.split('-')
        self.fixture = MATRIX['fixtures'][self.fixture_name]
        if not set(self.fixture['checks']) <= CHECKS:
            sys.exit(f'matrix.json: the fixture {self.fixture_name} has unknown checks {set(self.fixture["checks"]) - CHECKS}')

    def conditions(self, step):
        found = {'always', 'no-sql' if self.db == 'none' else 'sql'}
        if self.db == 'sqlite':
            found.add('sqlite')
        if self.db in ('mysql', 'postgres', 'mysqlpostgres'):
            found.add('server-sql')
        if self.cache == 'valkey' and step >= 2:
            found.add('valkey')
        return found

    def pick(self, key, step=1):
        mapping = self.fixture.get(key, {})
        return next((mapping[name] for name in PRIORITY if name in mapping and name in self.conditions(step)), None)

    def env(self, step):
        mapping, merged = self.fixture.get('env', {}), {}
        for name in reversed(PRIORITY):
            if name in mapping and name in self.conditions(step):
                merged.update(mapping[name])
        return merged

    def fill(self, text):
        return None if text is None else text.replace('{cell}', self.id)

    def create_database(self):
        # A fixture without database_in_create_form gets its database on the second deploy, so the matrix also proves Add resource.
        if not self.fixture.get('database_in_create_form'):
            return 'none'
        return {'mysql': 'mysql', 'mysqlpostgres': 'mysql', 'postgres': 'postgres'}.get(self.db, 'none')

    def db_step(self):
        """The step from which the app has its SQL database."""
        added_later = not self.fixture.get('database_in_create_form') and self.db in ('mysql', 'postgres', 'mysqlpostgres')
        return 2 if added_later else 1

    def expected_cache(self, step):
        """The cache driver that the verify endpoint must report, or None when it must report no cache."""
        if self.cache == 'valkey' and step >= 2:
            return 'redis'
        return self.pick('cache_without_valkey')


def cells_for(set_name, patterns):
    full = [f'{name}-{d}-{c}' for name, spec in MATRIX['fixtures'].items() for d in spec['databases'] for c in spec['caches']]
    cells = full if set_name == 'full' else MATRIX['sets'][set_name]
    if set(cells) - set(full):
        sys.exit(f'matrix.json: the set {set_name} has cells that no fixture supports: {sorted(set(cells) - set(full))}')
    return [c for c in cells if not patterns or any(fnmatch.fnmatch(c, p) for p in patterns)]


def curl(method, url, body=None, headers=(), jar=None, timeout=120, follow=False):
    """
    Every request goes through curl. macOS Local Network privacy can block the sockets of Python to an OrbStack machine
    with "No route to host", but it allows curl, which is a system binary. -k because the machines use the local Caddy CA.

    Returns (status, redirect URL or None, body). The status is 0 when the connection failed.
    """
    args = ['curl', '-s', '-k', '--max-time', str(timeout), '-X', method, url, '-o', '-', '-w', '\n%{http_code} %{redirect_url}']
    if follow:
        args.append('-L')
    if jar is not None:
        args += ['-b', str(jar), '-c', str(jar)]
    for header in headers:
        args += ['-H', header]
    if body is not None:
        args += ['-H', 'Content-Type: application/json', '--data-binary', json.dumps(body)]
    out = subprocess.run(args, capture_output=True, text=True, errors='replace').stdout
    text, _, meta = out.rpartition('\n')
    code, _, location = meta.partition(' ')
    return int(code or 0), location or None, text


class Dashboard:
    """Posts to the same routes as the dashboard forms, with the session cookie and the XSRF token of a browser."""

    def __init__(self, base):
        self.base = base
        STATE.mkdir(parents=True, exist_ok=True)
        self.jar = STATE / 'cookies.txt'

    def request(self, method, path, body=None):
        headers = ['Accept: text/html']
        if method != 'GET':
            headers.append(f'X-XSRF-TOKEN: {self.xsrf()}')
        return curl(method, self.base + path, body=body, headers=headers, jar=self.jar)

    def xsrf(self):
        token = self.cookie('XSRF-TOKEN')
        if token is None:
            self.request('GET', '/login')
            token = self.cookie('XSRF-TOKEN')
        return urllib.parse.unquote(token)

    def cookie(self, name):
        lines = self.jar.read_text().splitlines() if self.jar.exists() else []
        return next((parts[6] for parts in (line.split('\t') for line in lines) if len(parts) == 7 and parts[5] == name), None)

    def props(self, path):
        status, _, text = self.request('GET', path)
        match = re.search(r'data-page="([^"]+)"', text)
        if status != 200 or match is None:
            raise RuntimeError(f'GET {path} gave {status} without page data')
        return json.loads(html.unescape(match.group(1)))['props']

    def login(self, env):
        owner_file = STATE / 'owner.json'
        if self.request('GET', '/applications')[0] == 200:
            return 'the session is still valid'
        if not owner_file.exists():
            if not env.get('SETUP_URL'):
                sys.exit(f'{owner_file} is missing and the machine has no setup URL. Run scripts/machine.sh down and up.')
            owner = {'email': 'verify@example.com', 'password': secrets.token_hex(16)}
            self.request('GET', env['SETUP_URL'][len(self.base):])
            status, location, _ = self.request('POST', env['SETUP_URL'][len(self.base):], {
                'name': 'Verify', 'email': owner['email'], 'password': owner['password'], 'password_confirmation': owner['password'],
            })
            if status != 302 or not (location or '').endswith('/dashboard'):
                sys.exit(f'The setup page did not create the owner: {status} {location}')
            owner_file.write_text(json.dumps(owner))
            owner_file.chmod(0o600)
            return 'created the owner'
        owner = json.loads(owner_file.read_text())
        self.request('GET', '/login')
        status, location, _ = self.request('POST', '/login', {'email': owner['email'], 'password': owner['password']})
        if status != 302 or (location or '').endswith('/login'):
            sys.exit(f'The login failed: {status} {location}')
        return 'logged in'

    def wait_deployment(self, deployment_id, timeout):
        start = time.time()
        while True:
            status = self.props(f'/deployments/{deployment_id}')['deployment']['status']
            if status in ('live', 'failed', 'canceled') or time.time() - start > timeout:
                return status, round(time.time() - start)
            time.sleep(5)


def fetch(url, timeout=10):
    status, _, body = curl('GET', url, timeout=timeout, follow=True)
    return status, body


class Poller(threading.Thread):
    """Requests a URL four times a second and counts the answers that are not 200."""

    def __init__(self, url):
        super().__init__(daemon=True)
        self.url, self.total, self.failed, self.failures, self.running = url, 0, 0, [], True

    def run(self):
        while self.running:
            status, body = fetch(self.url, timeout=5)
            self.total += 1
            if status != 200:
                self.failed += 1
                self.failures.append(f'{status} {body[:120]}')
            time.sleep(0.25)


class CellRun:
    def __init__(self, dashboard, cluster_id, cell, out):
        self.dash, self.cluster_id, self.cell, self.out = dashboard, cluster_id, cell, out
        self.checks, self.skipped, self.timings, self.hits = [], [], {}, None
        self.secret = secrets.token_hex(12)
        out.mkdir(parents=True, exist_ok=True)

    def check(self, name, ok, detail='', needs=None):
        """needs names the entry of CHECKS that the check reads. A fixture without it gets a SKIP line, never a PASS."""
        if needs is not None and needs not in self.cell.fixture['checks']:
            self.skipped.append(name)
            print(f'  SKIP {name} (the {self.cell.fixture_name} fixture does not report {needs})', flush=True)
            return None
        self.checks.append({'name': name, 'ok': bool(ok), 'detail': str(detail)})
        print(f"  {'PASS' if ok else 'FAIL'} {name}" + (f' ({detail})' if detail != '' else ''), flush=True)
        return bool(ok)

    def save(self, name, data):
        path = self.out / name
        path.write_text(data if isinstance(data, str) else json.dumps(data, indent=2))

    def run(self):
        print(f'== {self.cell.id}', flush=True)
        try:
            self.drive()
        except Exception as error:  # noqa: BLE001 - one broken cell must not stop the matrix
            self.check('the cell ran to the end', False, f'{type(error).__name__}: {error}')
        verdict = 'pass' if self.checks and all(c['ok'] for c in self.checks) else 'fail'
        self.save('result.json', {'cell': self.cell.id, 'verdict': verdict, 'timings': self.timings, 'checks': self.checks, 'skipped': self.skipped})
        return verdict

    def drive(self):
        cell, fixture = self.cell, self.cell.fixture
        env = {'VERIFY_MARKER': f'{cell.id}-v1', **cell.env(1)}
        body = {
            'name': cell.id,
            'repository': repository_url(),
            'branch': fixture['branch'],
            'cluster_id': self.cluster_id,
            'type': fixture['type'],
            'env_profile': fixture['env_profile'],
            'port': fixture.get('port'),
            'size': 'xs',
            'min_instances': 1,
            'max_instances': 1,
            'cpu_target': 70,
            'build_command': cell.fill(cell.pick('build_command')),
            'deploy_commands': [cell.fill(c) for c in cell.pick('deploy_commands') or []],
            'processes': [],
            'database': cell.create_database(),
            'env': [
                *({'key': k, 'value': cell.fill(v), 'is_secret': False, 'available_at': 'runtime'} for k, v in env.items()),
                {'key': 'VERIFY_SECRET', 'value': self.secret, 'is_secret': True, 'available_at': 'runtime'},
                {'key': 'VERIFY_BUILD', 'value': cell.id, 'is_secret': False, 'available_at': 'build'},
            ],
        }
        self.save('config.json', {**body, 'env': [{**e, 'value': '<secret>'} if e['is_secret'] else e for e in body['env']]})

        status, location, _ = self.dash.request('POST', '/applications', body)
        created = re.search(r'/environments/(\d+)$', location or '')
        if not self.check('the form creates the application', status == 302 and created, f'{status} {location}'):
            return
        self.env_id = int(created.group(1))
        first = self.dash.props(f'/environments/{self.env_id}/deployments')['deployments'][0]['id']
        if not self.deployed(1, first, timeout=2400):
            return
        domains = self.dash.props(f'/environments/{self.env_id}')['domains']
        host = next((d['hostname'] for d in domains if d['generated']), None)
        if not self.check('the environment has a free address', host, host):
            return
        self.url = f'https://{host}'
        self.verify_step(1)

        self.change_step()
        status, location, _ = self.dash.request('POST', f'/environments/{self.env_id}/deployments')
        second = re.search(r'/deployments/(\d+)$', location or '')
        if not self.check('Deploy starts the second deployment', second, f'{status} {location}') or not self.deployed(2, second.group(1)):
            return
        self.verify_step(2)

        poller = Poller(self.url + fixture['verify_path'])
        poller.start()
        status, location, _ = self.dash.request('POST', f'/environments/{self.env_id}/deployments')
        third = re.search(r'/deployments/(\d+)$', location or '')
        live = self.check('Deploy starts the third deployment', third, f'{status} {location}') and self.deployed(3, third.group(1))
        time.sleep(3)
        poller.running = False
        poller.join()
        self.save('step-3-requests.json', {'total': poller.total, 'failed': poller.failed, 'failures': poller.failures[:50]})
        self.check('the redeploy under traffic fails no request', poller.total > 0 and poller.failed == 0, f'{poller.failed} of {poller.total} failed')
        if live:
            self.verify_step(3)

        settings = self.dash.request('GET', f'/environments/{self.env_id}/settings')[2]
        self.check('the settings page never shows the secret value', self.secret not in settings)
        self.save('canvas.json', self.dash.props(f'/environments/{self.env_id}'))

    def deployed(self, step, deployment_id, timeout=900):
        status, seconds = self.dash.wait_deployment(deployment_id, timeout)
        self.timings[f'deployment-{step}'] = seconds
        log = self.dash.props(f'/deployments/{deployment_id}')['deployment']['log']
        self.save(f'deployment-{step}.log', log)
        ok = self.check(f'deployment {step} goes live', status == 'live', f'{status} after {seconds}s')
        self.check(f'deployment {step} ran the deploy commands', f'deploy-marker-{self.cell.id}' in log)
        return ok

    def change_step(self):
        """The second deploy gets a new env value, a second SQL database named reports, and Valkey when the cell has them."""
        cell = self.cell
        changes = {'VERIFY_MARKER': f'{cell.id}-v2', **{k: v for k, v in cell.env(2).items() if cell.env(1).get(k) != v}}
        for key, value in changes.items():
            status, _, _ = self.dash.request('POST', f'/environments/{self.env_id}/env', {
                'key': key, 'value': value, 'is_secret': False, 'available_at': 'runtime',
            })
            self.check(f'the env value {key} changes', status == 302, status)
        if cell.db_step() == 2:
            self.attach('postgres' if cell.db == 'postgres' else 'mysql')
        if cell.db == 'mysqlpostgres':
            self.attach('postgres', name='reports')
        if cell.cache == 'valkey':
            self.attach('valkey')

    def attach(self, kind, name=None):
        label = f'{kind} {name}' if name else kind
        status, _, _ = self.dash.request('POST', f'/environments/{self.env_id}/datastores', {'kind': kind, **({'name': name} if name else {})})
        errors = self.dash.props(f'/environments/{self.env_id}').get('errors', {})
        self.check(f'Add resource attaches {label}', status == 302 and not errors, errors or status)
        start, state = time.time(), None
        while time.time() - start < 300:
            attachments = self.dash.props(f'/environments/{self.env_id}')['datastores']['attachments']
            state = next((a['status'] for a in attachments if a['kind'] == kind and (name is None or a['name'] == name)), None)
            if state in ('ready', 'failed'):
                break
            time.sleep(5)
        self.check(f'{label} becomes ready', state == 'ready', f'{state} after {round(time.time() - start)}s')

    def verify_step(self, step):
        cell, fixture = self.cell, self.cell.fixture
        status, page = fetch(self.url + '/')
        for _ in range(24):
            if status == 200:
                break
            time.sleep(5)
            status, page = fetch(self.url + '/')
        self.save(f'step-{step}-page.html', page)
        self.check(f'step {step}: the page answers 200 over {self.url.split(":")[0].upper()}', status == 200 and '<title>' in page, f'{status} {self.url}/')

        status, text = fetch(self.url + fixture['verify_path'])
        self.save(f'step-{step}-verify.json', text)
        try:
            report = json.loads(text)
        except ValueError:
            self.check(f'step {step}: {fixture["verify_path"]} answers JSON', False, f'{status} {text[:200]}')
            return
        marker = f'{cell.id}-v{min(step, 2)}'
        self.check(f'step {step}: the runtime env value', report.get('marker') == marker, report.get('marker'), needs='marker')
        self.check(f'step {step}: the build saw its env value or command', report.get('build') == cell.id, report.get('build'), needs='build')
        self.check(f'step {step}: the app reads the secret', report.get('secret_sha') == hashlib.sha1(self.secret.encode()).hexdigest(), needs='secret')

        db = report.get('db') or {}
        if cell.db != 'none' and step < cell.db_step():
            self.check(f'step {step}: no database is attached yet', report.get('db') is None, json.dumps(report.get('db')), needs='db')
        elif cell.db != 'none':
            self.check(f'step {step}: the {DRIVER[cell.db]} database answers', db.get('ok') and db.get('driver') == DRIVER[cell.db], json.dumps(report.get('db')), needs='db')
            if cell.db != 'sqlite':
                deploys = step - cell.db_step() + 1
                self.check(f'step {step}: each deploy command wrote a row', db.get('deploys', 0) >= deploys, f"{db.get('deploys')} >= {deploys}", needs='db')
                if self.hits is not None:
                    self.check(f'step {step}: the rows of earlier steps are still there', db.get('hits', 0) > self.hits, f"{db.get('hits')} > {self.hits}", needs='db')
            self.hits = db.get('hits')

        if cell.db == 'mysqlpostgres' and step >= 2:
            reports = (report.get('extra') or {}).get('reports') or {}
            self.check(f'step {step}: the pgsql database answers through {REPORTS_URL[fixture["env_profile"]]}', reports.get('ok') is True and reports.get('driver') == 'pgsql', json.dumps(report.get('extra')), needs='extra')

        expected = cell.expected_cache(step)
        cache = report.get('cache')
        if expected is None:
            self.check(f'step {step}: no cache is in use', cache is None, json.dumps(cache), needs='cache')
        else:
            self.check(f'step {step}: the {expected} cache works', cache and cache.get('ok') and cache.get('driver') == expected, json.dumps(cache), needs='cache')


def run(args):
    env = machine_env()
    dashboard = Dashboard(env['BASE'])
    print(dashboard.login(env))
    cells = cells_for(args.set, args.only)
    run_id = time.strftime('%Y%m%d-%H%M%S')
    out = RUNS / run_id
    out.mkdir(parents=True)
    cluster_id = dashboard.props('/applications/create')['clusters'][0]['id']
    head = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
    (out / 'machine.json').write_text(json.dumps({**{k: v for k, v in env.items() if k != 'SETUP_URL'}, 'head_at_run': head, 'cells': cells}, indent=2))
    print(f'Run {run_id}: {len(cells)} cells on {env["MACHINE"]} ({env["BASE"]})')

    rows = []
    for cell_id in cells:
        started = time.time()
        cell_run = CellRun(dashboard, cluster_id, Cell(cell_id), out / cell_id)
        verdict = cell_run.run()
        failed = [c['name'] for c in cell_run.checks if not c['ok']]
        rows.append((cell_id, verdict, round(time.time() - started), cell_run.timings.get('deployment-1', '-'), len(cell_run.skipped), '; '.join(failed)))

    lines = ['| Cell | Verdict | Seconds | First deploy (s) | Skipped checks | Failed checks |', '|---|---|---|---|---|---|']
    lines += [f'| {c} | {v} | {s} | {f} | {k} | {x or "-"} |' for c, v, s, f, k, x in rows]
    (out / 'summary.md').write_text('\n'.join(lines) + '\n')
    print('\n'.join(lines))
    passed = sum(1 for r in rows if r[1] == 'pass')
    print(f'\n{passed} of {len(rows)} cells pass. Evidence: {out}')
    return 0 if passed == len(rows) else 1


def docker(*args, env=None):
    return subprocess.run(['docker', *args], capture_output=True, text=True, errors='replace', env=None if env is None else {**os.environ, **env})


# A password with URL characters, so a fixture must decode the URLs that the env profile encodes.
LOCAL_PASSWORD = 'pa:ss@w/rd+1'
LOCAL_SERVERS = {
    'mysql': {'image': 'mysql', 'port': 3306, 'args': ['-e', 'MYSQL_RANDOM_ROOT_PASSWORD=yes', '-e', 'MYSQL_DATABASE=verify', '-e', 'MYSQL_USER=verify', '-e', f'MYSQL_PASSWORD={LOCAL_PASSWORD}'],
              'command': [], 'ready': ['mysqladmin', 'ping', '-h', '127.0.0.1', '-u', 'verify', f'-p{LOCAL_PASSWORD}']},
    'postgres': {'image': 'postgres', 'port': 5432, 'args': ['-e', 'POSTGRES_DB=verify', '-e', 'POSTGRES_USER=verify', '-e', f'POSTGRES_PASSWORD={LOCAL_PASSWORD}'],
                 'command': [], 'ready': ['pg_isready', '-h', '127.0.0.1', '-U', 'verify']},
    'valkey': {'image': 'valkey/valkey', 'port': 6379, 'args': [], 'command': ['valkey-server', '--requirepass', LOCAL_PASSWORD],
               'ready': ['valkey-cli', '--no-auth-warning', '-a', LOCAL_PASSWORD, 'ping']},
}


class LocalRun(CellRun):
    """
    Runs one cell in Docker on this computer, after step 2 of a real run: the image from the Dockerfile that the preset of
    this checkout writes, the datastores of the cell, three runs of the deploy commands, and the web container. Then the
    same checks as steps 2 and 3. It proves a fixture before a matrix run, and it needs no machine.
    """

    def __init__(self, cell, out):
        super().__init__(None, None, cell, out)
        self.name = f'oc-verify-local-{cell.id}'

    def attachments(self):
        cell = self.cell
        sql = {'mysql': [('mysql', 'main')], 'postgres': [('postgres', 'main')], 'mysqlpostgres': [('mysql', 'main'), ('postgres', 'reports')]}.get(cell.db, [])
        found = [{'kind': kind, 'host': f'{self.name}-{kind}', 'port': LOCAL_SERVERS[kind]['port'], 'database': 'verify', 'username': 'verify',
                  'password': LOCAL_PASSWORD, 'name': name, 'default': name == 'main'} for kind, name in sql]
        if cell.cache == 'valkey':
            found.append({'kind': 'valkey', 'host': f'{self.name}-valkey', 'port': 6379, 'password': LOCAL_PASSWORD})
        return found

    def drive(self):
        cell, attachments, build_env = self.cell, self.attachments(), {'VERIFY_BUILD': self.cell.id}
        with tempfile.TemporaryDirectory(prefix=f'{self.name}-') as work:
            app = Path(work) / 'app'
            fixture_tree(cell.fixture_name, cell.fixture, app)
            planned = subprocess.run(['php', str(SKILL / 'scripts' / 'local-plan.php'), json.dumps({
                'dashboard': str(ROOT / 'dashboard'), 'dir': str(app), 'type': cell.fixture['type'], 'env_profile': cell.fixture['env_profile'],
                'port': cell.fixture.get('port'), 'build_env': build_env, 'build_command': cell.fill(cell.pick('build_command')),
                'attachments': attachments,
            })], capture_output=True, text=True)
            if planned.returncode != 0:
                raise RuntimeError(f'local-plan.php failed: {planned.stdout}{planned.stderr}')
            try:
                plan = json.loads(planned.stdout)
            except json.JSONDecodeError:
                raise RuntimeError(f'local-plan.php printed no JSON: {planned.stdout[-2000:]}{planned.stderr}')
            build = docker('build', '--progress=plain', '-f', str(app / plan['dockerfile']), '--build-arg', f"OPENCLOUD_BUILD_ENV={plan['build_env_key']}",
                           '--secret', 'id=VERIFY_BUILD,env=VERIFY_BUILD', '-t', self.name, str(app), env=build_env)
        self.save('build.log', build.stdout + build.stderr)
        if not self.check('the image builds with the Dockerfile of the preset', build.returncode == 0, f"{plan['dockerfile']}, see build.log"):
            print('\n'.join((build.stdout + build.stderr).splitlines()[-30:]))
            return

        env = {**plan['env'], 'VERIFY_MARKER': f'{cell.id}-v2', **{k: cell.fill(v) for k, v in cell.env(2).items()}, 'VERIFY_SECRET': self.secret}
        pass_env = [arg for key in env for arg in ('-e', key)]
        try:
            docker('network', 'create', self.name)
            for a in attachments:
                server = LOCAL_SERVERS[a['kind']]
                docker('run', '-d', '--name', a['host'], '--network', self.name, *server['args'], f"{server['image']}:{plan['versions'][a['kind']]}", *server['command'])
            for a in attachments:
                start = time.time()
                while docker('exec', a['host'], *LOCAL_SERVERS[a['kind']]['ready']).returncode != 0 and time.time() - start < 180:
                    time.sleep(2)
                self.check(f"the local {a['kind']} server answers", time.time() - start < 180, f'after {round(time.time() - start)}s')
            commands = ' && '.join(cell.fill(c) for c in cell.pick('deploy_commands') or [])
            for step in (1, 2, 3):
                deploy = docker('run', '--rm', '--network', self.name, *pass_env, '--entrypoint', 'sh', self.name, '-c', commands, env=env)
                self.save(f'deployment-{step}.log', deploy.stdout + deploy.stderr)
                self.check(f'deployment {step} ran the deploy commands', deploy.returncode == 0 and f'deploy-marker-{cell.id}' in deploy.stdout, f'exit {deploy.returncode}')
            docker('run', '-d', '--name', f'{self.name}-web', '--network', self.name, *pass_env, '-p', f"127.0.0.1::{plan['port']}", self.name, env=env)
            address = docker('port', f'{self.name}-web', f"{plan['port']}/tcp").stdout.split()
            if not self.check('the web container publishes its port', address, plan['port']):
                return
            self.url = f'http://{address[0]}'
            self.verify_step(2)
            self.verify_step(3)
        finally:
            logs = docker('logs', f'{self.name}-web')
            self.save('web.log', logs.stdout + logs.stderr)
            docker('rm', '-f', f'{self.name}-web', *(a['host'] for a in attachments))
            docker('network', 'rm', self.name)
            docker('rmi', self.name)


def local(args):
    out = RUNS / time.strftime('%Y%m%d-%H%M%S-local')
    verdicts = [LocalRun(Cell(cell_id), out / cell_id).run() for cell_id in cells_for(args.set, args.only)]
    print(f"\n{verdicts.count('pass')} of {len(verdicts)} cells pass locally. Evidence: {out}")
    return 0 if verdicts.count('pass') == len(verdicts) else 1


def doctor(_args):
    env = machine_env()
    ok = True

    def line(good, text):
        nonlocal ok
        ok = ok and good
        print(f"{'OK  ' if good else 'FAIL'} {text}")

    machines = subprocess.run(['orb', 'list'], capture_output=True, text=True).stdout
    line(any(l.split()[:2] == [env['MACHINE'], 'running'] for l in machines.splitlines()), f"the machine {env['MACHINE']} runs")
    status, _ = fetch(env['BASE'] + '/up')
    line(status == 200, f"the dashboard answers {env['BASE']}/up ({status})")
    image = subprocess.run(['orb', '-m', env['MACHINE'], 'sudo', 'k3s', 'kubectl', '-n', 'opencloud', 'get', 'deploy', 'dashboard',
                            '-o', 'jsonpath={.spec.template.spec.containers[0].image}'], capture_output=True, text=True).stdout
    head = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
    built = env.get('BUILT_FROM', '')
    line(built.startswith(head), f'the machine runs {image or "an unknown image"}, built from {built or "an unknown commit"}; HEAD is {head}')
    if status == 200:
        dashboard = Dashboard(env['BASE'])
        print(f'OK   {dashboard.login(env)}')
        cluster = dashboard.props('/applications/create')['clusters'][0]
        capacity = json.loads(dashboard.request('GET', f"/clusters/{cluster['id']}/capacity")[2])
        line(capacity['state'] == 'ok', f"capacity of {cluster['name']}: {capacity.get('requested')} requested of {capacity.get('allocatable')}")
    line(fixtures(argparse.Namespace(action='check')) == 0, 'the fixture branches match the fixture folders')
    return 0 if ok else 1


def fixture_tree(name, spec, target):
    """Writes the files of one fixture app into target: the base repository, if any, then the folder of the fixture."""
    if 'base' in spec:
        base = target.parent / 'base'
        base.mkdir()
        git = ['git', '-C', str(base)]
        subprocess.run([*git, 'init', '--quiet'], check=True)
        subprocess.run([*git, 'fetch', '--quiet', '--depth', '1', spec['base']['repository'], spec['base']['commit']], check=True)
        subprocess.run([*git, 'checkout', '--quiet', 'FETCH_HEAD'], check=True)
        shutil.copytree(base, target, dirs_exist_ok=True, ignore=shutil.ignore_patterns('.git'))
    shutil.copytree(SKILL / 'fixtures' / name, target, dirs_exist_ok=True)


def fixtures(args):
    """Each fixture app lives on its own branch of the fixture repository, because opencloud deploys the root of a branch."""
    different = 0
    for name, spec in MATRIX['fixtures'].items():
        with tempfile.TemporaryDirectory(prefix=f'oc-verify-{name}-') as work:
            repo = Path(work) / 'repo'
            remote = repository_url()
            exists = subprocess.run(['git', 'ls-remote', '--exit-code', '--heads', remote, spec['branch']], capture_output=True).returncode == 0
            if exists:
                subprocess.run(['git', 'clone', '--quiet', '--depth', '1', '--branch', spec['branch'], remote, str(repo)], check=True)
            else:
                subprocess.run(['git', 'init', '--quiet', '--initial-branch', spec['branch'], str(repo)], check=True)
                subprocess.run(['git', '-C', str(repo), 'remote', 'add', 'origin', remote], check=True)
            for entry in repo.iterdir():
                if entry.name != '.git':
                    shutil.rmtree(entry) if entry.is_dir() else entry.unlink()
            fixture_tree(name, spec, repo)
            subprocess.run(['git', '-C', str(repo), 'add', '--all'], check=True)
            changed = subprocess.run(['git', '-C', str(repo), 'diff', '--cached', '--quiet'], check=False).returncode != 0
            if not changed:
                print(f'{name}: the branch {spec["branch"]} is up to date')
                continue
            different += 1
            if args.action == 'check':
                print(f'{name}: the branch {spec["branch"]} differs from fixtures/{name}. Run matrix.py fixtures sync.')
                continue
            subprocess.run(['git', '-C', str(repo), 'commit', '--quiet', '-m', f'Sync the {name} fixture'], check=True)
            subprocess.run(['git', '-C', str(repo), '-c', 'credential.helper=', '-c', 'credential.helper=!gh auth git-credential',
                            'push', '--quiet', 'origin', f'HEAD:refs/heads/{spec["branch"]}'], check=True)
            print(f'{name}: pushed the branch {spec["branch"]}')
    return 1 if args.action == 'check' and different else 0


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest='command', required=True)
    commands.add_parser('doctor').set_defaults(func=doctor)
    fixture_parser = commands.add_parser('fixtures')
    fixture_parser.add_argument('action', choices=['sync', 'check'])
    fixture_parser.set_defaults(func=fixtures)
    for name, func in (('list', None), ('run', run), ('local', local)):
        sub = commands.add_parser(name)
        sub.add_argument('--set', default='smoke', choices=['smoke', 'full'], help='smoke covers each value of each fixture at least once; full is every cell')
        sub.add_argument('--only', action='append', default=[], help='keep the cells that match this glob, for example "php-*"')
        sub.set_defaults(func=func or (lambda a: print('\n'.join(cells_for(a.set, a.only))) or 0))
    args = parser.parse_args()
    sys.exit(args.func(args))


if __name__ == '__main__':
    main()
