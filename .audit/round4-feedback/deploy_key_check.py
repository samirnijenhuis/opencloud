#!/usr/bin/env python3
"""Proves the deploy key on a private repository: create with the key, a refused deploy, then an accepted deploy."""
import json
import re
import subprocess
import sys
import time

ROOT = '/Users/samirnijenhuis/Projects/opencloud'
REPO = 'samirnijenhuis/opencloud-verify-fixtures'
sys.path.insert(0, f'{ROOT}/.claude/skills/verify-opencloud/scripts')
import matrix  # noqa: E402

results = []


def check(name, ok, detail=''):
    results.append(bool(ok))
    print(f"  {'PASS' if ok else 'FAIL'} {name}" + (f' ({detail})' if detail != '' else ''))
    return ok


def gh(*args):
    return subprocess.run(['gh', *args], capture_output=True, text=True)


env = dict(line.split('=', 1) for line in open(f'{ROOT}/.verify/state/machine.env').read().split())
dash = matrix.Dashboard(env['BASE'])
print(dash.login(env))

visibility = json.loads(gh('repo', 'view', REPO, '--json', 'visibility').stdout)['visibility']
check('the fixture repository is private', visibility == 'PRIVATE', visibility)
status, _, _ = matrix.curl('GET', f'https://github.com/{REPO}')
check('github.com does not show the repository without a login', status == 404, status)

name = f'dk-laravel-{int(time.time())}'
cluster_id = dash.props('/applications/create')['clusters'][0]['id']
status, location, _ = dash.request('POST', '/applications', {
    'name': name, 'repository': f'git@github.com:{REPO}.git', 'branch': 'laravel', 'cluster_id': cluster_id,
    'type': 'laravel', 'size': 'xs', 'min_instances': 1, 'max_instances': 1, 'cpu_target': 70,
    'build_command': 'touch database/database.sqlite && php artisan migrate --force',
    'deploy_commands': ['php artisan verify:mark-deploy deploy-key'], 'processes': [], 'database': 'none',
    'deploy_key': True,
    'env': [{'key': 'DB_CONNECTION', 'value': 'sqlite', 'is_secret': False, 'available_at': 'both'}],
})
app_match = re.search(r'/applications/(\d+)$', location or '')
check('the create form with a deploy key opens the application page', status == 302 and app_match, f'{status} {location}')
app_id = app_match.group(1)
page = dash.props(f'/applications/{app_id}')
public_key = page['deployPublicKey']
check('the page shows an ed25519 public key', (public_key or '').startswith('ssh-ed25519 '), (public_key or '')[:30])
env_id = page['environments'][0]['id']
check('the create flow started no deployment', dash.props(f'/environments/{env_id}/deployments')['deployments'] == [])

status, location, _ = dash.request('POST', f'/environments/{env_id}/deployments')
errors = dash.props(f'/environments/{env_id}').get('errors', {})
check('a deploy before GitHub knows the key gives the step that fixes it', 'GitHub refused the deploy key' in errors.get('deploy', ''), errors)

added = subprocess.run(['gh', 'repo', 'deploy-key', 'add', '-', '-R', REPO, '--title', f'opencloud oc-verify {name}'], input=public_key, capture_output=True, text=True)
check('gh adds the public key as a read-only deploy key', added.returncode == 0, (added.stdout + added.stderr).strip())
keys = json.loads(gh('api', f'repos/{REPO}/keys').stdout)
key = next((k for k in keys if k['title'] == f'opencloud oc-verify {name}'), None)
check('the key on GitHub is read-only', key is not None and key['read_only'] is True, key and key['read_only'])

try:
    status, location, _ = dash.request('POST', f'/environments/{env_id}/deployments')
    deployment = re.search(r'/deployments/(\d+)$', location or '')
    check('Deploy starts a deployment once GitHub knows the key', deployment, f'{status} {location}')
    state, seconds = dash.wait_deployment(deployment.group(1), 1500)
    steps = dash.props(f'/deployments/{deployment.group(1)}')['steps']
    check('the deployment of the private repository goes live', state == 'live', f'{state} after {seconds}s')
    clone = next((s for s in steps if s['key'] == 'preparing_build'), {'lines': []})
    print('  clone lines:', [line['text'] for line in clone['lines']][:3])
    check('the deploy commands ran', any('deploy-key' in line['text'] for s in steps for line in s['lines']))
    host = next(d['hostname'] for d in dash.props(f'/environments/{env_id}')['domains'] if d['generated'])
    for _ in range(24):
        status, body = matrix.fetch(f'https://{host}/')
        if status == 200:
            break
        time.sleep(5)
    check('the site of the private repository answers 200', status == 200, f'https://{host}/ {status}')
finally:
    if key is not None:
        removed = gh('repo', 'deploy-key', 'delete', str(key['id']), '-R', REPO)
        check('the deploy key is removed from GitHub again', removed.returncode == 0, (removed.stdout + removed.stderr).strip())

print(f'{sum(results)} of {len(results)} checks pass')
sys.exit(0 if all(results) else 1)
