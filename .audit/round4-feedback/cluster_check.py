#!/usr/bin/env python3
"""Proves the round 4 process kinds and the second bucket on the oc-verify machine, through the dashboard routes."""
import base64
import json
import subprocess
import sys
import time

ROOT = '/Users/samirnijenhuis/Projects/opencloud'
sys.path.insert(0, f'{ROOT}/.claude/skills/verify-opencloud/scripts')
import matrix  # noqa: E402

APP = sys.argv[1] if len(sys.argv) > 1 else 'laravel-postgres-valkey'
results = []


def check(name, ok, detail=''):
    results.append(ok)
    print(f"  {'PASS' if ok else 'FAIL'} {name}" + (f' ({detail})' if detail else ''))
    return ok


def kube(*args):
    out = subprocess.run(['orb', '-m', 'oc-verify', 'sudo', 'k3s', 'kubectl', *args], capture_output=True, text=True)
    return out.stdout + out.stderr


env = dict(line.split('=', 1) for line in open(f'{ROOT}/.verify/state/machine.env').read().split())
dash = matrix.Dashboard(env['BASE'])
print(dash.login(env))

apps = dash.props('/applications')['applications']
app = max((a for a in apps if a['name'] == APP), key=lambda a: a['id'])
env_id = app['production_id']
ns = f'oc-env-{env_id}'
print(f'== {APP}, environment {env_id}, namespace {ns}')


def processes():
    return {p['name']: p for p in dash.props(f'/environments/{env_id}')['processes']}


def deploy(label):
    status, location, _ = dash.request('POST', f'/environments/{env_id}/deployments')
    deployment_id = location.rsplit('/', 1)[-1]
    state, seconds = dash.wait_deployment(deployment_id, 900)
    check(f'{label}: the deployment goes live', state == 'live', f'{state} after {seconds}s')
    steps = dash.props(f'/deployments/{deployment_id}')['steps']
    check(f'{label}: the page has 7 finished steps', [s['status'] for s in steps] == ['finished'] * 7, [s['status'] for s in steps])


def objects():
    names = {}
    for kind in ('deployment', 'cronjob', 'hpa'):
        names[kind] = set(kube('get', kind, '-n', ns, '-o', 'jsonpath={.items[*].metadata.name}').split())
    return names


for name in list(processes()):
    dash.request('DELETE', f"/processes/{processes()[name]['id']}")

status, _, _ = dash.request('POST', f'/environments/{env_id}/processes', {
    'name': 'worker', 'kind': 'long_running', 'command': 'php artisan queue:work --sleep=3 --tries=3',
    'size': 'xs', 'min_instances': 1, 'max_instances': 3, 'cpu_target': 70,
})
check('the sheet route adds a long-running worker with 1 to 3 instances', status == 302 and 'worker' in processes(), status)
status, _, _ = dash.request('POST', f'/environments/{env_id}/processes', {
    'name': 'scheduler', 'kind': 'scheduled', 'command': 'php artisan schedule:run', 'schedule': '* * * * *',
    'size': 'xs', 'min_instances': 1, 'max_instances': 1, 'cpu_target': 70,
})
check('the sheet route adds a scheduled scheduler', status == 302 and processes().get('scheduler', {}).get('kind') == 'scheduled', status)

attachments = dash.props(f'/environments/{env_id}')['datastores']['attachments']
if not any(a['kind'] == 'rustfs' and a['name'] == 'main' for a in attachments):
    dash.request('POST', f'/environments/{env_id}/datastores', {'kind': 'rustfs', 'name': 'main'})
if not any(a['kind'] == 'rustfs' and a['name'] == 'uploads' for a in attachments):
    dash.request('POST', f'/environments/{env_id}/datastores', {'kind': 'rustfs', 'name': 'uploads'})
for _ in range(60):
    buckets = {a['name']: a for a in dash.props(f'/environments/{env_id}')['datastores']['attachments'] if a['kind'] == 'rustfs'}
    if all(b['status'] in ('ready', 'failed') for b in buckets.values()) and len(buckets) == 2:
        break
    time.sleep(5)
check('two buckets, main and uploads, are ready', sorted(buckets) == ['main', 'uploads'] and all(b['status'] == 'ready' for b in buckets.values()),
      {n: b['status'] for n, b in buckets.items()})
check('main is the default bucket and uploads has a disk line', buckets['main']['is_default'] and not buckets['uploads']['is_default'] and 'UPLOADS_AWS' in (buckets['uploads']['config_line'] or ''),
      buckets['uploads']['config_line'])

deploy('deploy 1')
o = objects()
check('Deployments web and worker, and no Deployment scheduler', {'web', 'worker'} <= o['deployment'] and 'scheduler' not in o['deployment'], o['deployment'])
check('CronJob scheduler exists', 'scheduler' in o['cronjob'], o['cronjob'])
check('HPA worker exists for 1 to 3 instances', 'worker' in o['hpa'], o['hpa'])
cron = json.loads(kube('get', 'cronjob', 'scheduler', '-n', ns, '-o', 'json'))
spec = cron['spec']
check('the CronJob runs every minute with Allow and history limits 1 and 1',
      (spec['schedule'], spec['concurrencyPolicy'], spec['successfulJobsHistoryLimit'], spec['failedJobsHistoryLimit']) == ('* * * * *', 'Allow', 1, 1), spec['schedule'])

secret = max((s for s in kube('get', 'secret', '-n', ns, '-o', 'name').split() if s.startswith('secret/env-')), key=lambda s: int(s.rsplit('-', 1)[-1]))
data = json.loads(kube('get', secret, '-n', ns, '-o', 'json'))['data']
values = {k: base64.b64decode(v).decode() for k, v in data.items()}
check('the env Secret has the default AWS_BUCKET and the prefixed UPLOADS_AWS_BUCKET', 'AWS_BUCKET' in values and 'UPLOADS_AWS_BUCKET' in values and values['AWS_BUCKET'] != values['UPLOADS_AWS_BUCKET'],
      f"{values.get('AWS_BUCKET')} / {values.get('UPLOADS_AWS_BUCKET')}")

print('  waiting up to 150 seconds for a scheduler run')
for _ in range(30):
    jobs = json.loads(kube('get', 'jobs', '-n', ns, '-l', 'opencloud.dev/process=scheduler', '-o', 'json'))['items']
    if any(j.get('status', {}).get('succeeded') for j in jobs):
        break
    time.sleep(5)
check('a scheduler Job ran and succeeded', any(j.get('status', {}).get('succeeded') for j in jobs), [j['metadata']['name'] for j in jobs])
time.sleep(12)
live = dash.props(f'/environments/{env_id}')
status_json = json.loads(matrix.curl('GET', env['BASE'] + f'/environments/{env_id}/status', headers=['Accept: application/json'], jar=dash.jar)[2])
scheduled = [p for p in status_json.get('processes', []) + status_json.get('scheduled', []) if p.get('name') == 'scheduler']
print('  live status of scheduler:', json.dumps(scheduled))

rustfs = next(p for p in kube('get', 'pods', '-A', '-o', 'name').split() if 'rustfs' in p)
rustfs_ns = next(line.split()[0] for line in kube('get', 'pods', '-A', '--no-headers').splitlines() if rustfs.split('/')[1] in line)
script = (
    f"rc alias set up http://127.0.0.1:9000 {values['UPLOADS_AWS_ACCESS_KEY_ID']} {values['UPLOADS_AWS_SECRET_ACCESS_KEY']} >/dev/null"
    f" && echo hello > /tmp/round4.txt && rc cp /tmp/round4.txt up/{values['UPLOADS_AWS_BUCKET']}/round4.txt >/dev/null && echo WRITE_OK;"
    f" rc ls up/{values['AWS_BUCKET']} >/dev/null 2>&1 && echo CROSS_READ || echo CROSS_DENIED"
)
out = kube('exec', '-n', rustfs_ns, rustfs, '-c', 'rc', '--', 'sh', '-c', script)
check('the uploads key writes to its own bucket', 'WRITE_OK' in out, out.strip()[-200:])
check('the uploads key cannot read the main bucket', 'CROSS_DENIED' in out, out.strip()[-200:])

worker = processes()['worker']
dash.request('PATCH', f"/processes/{worker['id']}", {**{k: worker[k] for k in ('name', 'kind', 'command', 'size', 'cpu_target')}, 'min_instances': 1, 'max_instances': 1})
scheduler = processes()['scheduler']
dash.request('PATCH', f"/processes/{scheduler['id']}", {'name': 'scheduler', 'kind': 'long_running', 'command': 'php artisan schedule:work', 'size': 'xs', 'min_instances': 1, 'max_instances': 1, 'cpu_target': 70})
deploy('deploy 2, worker 1 to 1 and scheduler long-running')
o = objects()
check('HPA worker is gone', 'worker' not in o['hpa'], o['hpa'])
check('CronJob scheduler is gone and Deployment scheduler exists', 'scheduler' not in o['cronjob'] and 'scheduler' in o['deployment'], (o['cronjob'], o['deployment']))

scheduler = processes()['scheduler']
dash.request('PATCH', f"/processes/{scheduler['id']}", {'name': 'scheduler', 'kind': 'scheduled', 'command': 'php artisan schedule:run', 'schedule': '* * * * *', 'size': 'xs', 'min_instances': 1, 'max_instances': 1, 'cpu_target': 70})
deploy('deploy 3, scheduler scheduled again')
o = objects()
check('Deployment scheduler is gone and CronJob scheduler is back', 'scheduler' not in o['deployment'] and 'scheduler' in o['cronjob'], (o['cronjob'], o['deployment']))

print(f'{sum(results)} of {len(results)} checks pass')
sys.exit(0 if all(results) else 1)
