import InputError from '@/components/input-error';
import ProcessFields from '@/components/process-fields';
import { ShellEditor } from '@/components/shell-editor';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { parseDotenv, sizeLabel, sizeText } from '@/lib/format';
import { applicationTypeLabels, type ApplicationType, type EnvProfileKind, type ProcessValues, type Size, type SizeOption } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import { Chip, envProfiles, presetText, type Preset } from './presets';

type Cluster = { id: number; name: string; kind: string; ingress_ip: string | null };
type EnvRow = { key: string; value: string; is_secret: boolean };
type Database = 'mysql' | 'postgres' | 'none';

type FormData = {
    repository: string;
    branch: string;
    cluster_id: string;
    type: ApplicationType;
    env_profile: EnvProfileKind;
    port: string;
    size: Size;
    min_instances: number;
    max_instances: number;
    cpu_target: number;
    build_command: string;
    deploy_commands: string;
    processes: ProcessValues[];
    env: EnvRow[];
    domain: string;
    database: Database;
};

const looksSecret = (key: string) => /(SECRET|PASSWORD|TOKEN|KEY|AUTH)/i.test(key);

function Row({ label, hint, htmlFor, children }: { label: string; hint?: string; htmlFor?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-2 border-b py-5 sm:grid-cols-[11rem_1fr] sm:gap-6">
            <div>
                <Label htmlFor={htmlFor} className="text-sm font-medium">
                    {label}
                </Label>
                {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
            </div>
            <div className="min-w-0 space-y-2">{children}</div>
        </div>
    );
}

export default function ApplicationCreate({
    clusters,
    defaultClusterId,
    sizes,
    presets,
}: {
    clusters: Cluster[];
    defaultClusterId: number | null;
    sizes: SizeOption[];
    presets: Preset[];
}) {
    const defaults = (preset: Preset) => ({
        type: preset.value,
        env_profile: preset.env_profile,
        port: String(preset.port),
        deploy_commands: preset.deploy_commands.join('\n'),
        processes: preset.processes,
        database: preset.database,
    });
    const form = useForm<FormData>({
        repository: '',
        branch: '',
        cluster_id: defaultClusterId ? String(defaultClusterId) : '',
        size: 's',
        min_instances: 1,
        max_instances: 1,
        cpu_target: 70,
        build_command: '',
        env: [],
        domain: '',
        ...defaults(presets[0]),
    });
    const { data, setData, errors, processing } = form;
    const [pasting, setPasting] = useState(false);
    const [pasted, setPasted] = useState('');
    const preset = presets.find((each) => each.value === data.type) ?? presets[0];

    const changePreset = (next: Preset) => setData((current) => ({ ...current, ...defaults(next) }));

    const setProcess = (index: number, change: Partial<ProcessValues>) => {
        setData(
            'processes',
            data.processes.map((row, i) => (i === index ? { ...row, ...change } : row)),
        );
    };

    const processErrors = (index: number) => ({
        name: errors[`processes.${index}.name` as keyof typeof errors],
        kind: errors[`processes.${index}.kind` as keyof typeof errors],
        command: errors[`processes.${index}.command` as keyof typeof errors],
        schedule: errors[`processes.${index}.schedule` as keyof typeof errors],
    });

    const setEnv = (index: number, change: Partial<EnvRow>) => {
        setData(
            'env',
            data.env.map((row, i) => (i === index ? { ...row, ...change } : row)),
        );
    };

    const addPasted = () => {
        const parsed = parseDotenv(pasted).map((row) => ({ ...row, is_secret: looksSecret(row.key) }));
        const keys = new Set(parsed.map((row) => row.key));

        setData('env', [...data.env.filter((row) => !keys.has(row.key)), ...parsed]);
        setPasted('');
        setPasting(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((current) => ({
            ...current,
            port: preset.chooses_port ? current.port : null,
            deploy_commands: current.deploy_commands.split('\n'),
            env: current.env.filter((row) => row.key.trim() !== ''),
        }));
        form.post(route('applications.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Applications', href: route('applications.index') },
                { title: 'New application', href: route('applications.create') },
            ]}
        >
            <Head title="New application" />

            <form onSubmit={submit} className="mx-auto w-full max-w-3xl p-4">
                <h1 className="text-2xl font-semibold tracking-tight">New application</h1>
                <p className="text-muted-foreground mt-1 text-sm">A repository is enough. Every other field has a default that works.</p>

                <div className="mt-4">
                    <Row label="Repository" hint="owner/repo, or a full https address">
                        <div className="flex gap-2">
                            <Input
                                aria-label="Repository"
                                className="flex-1"
                                placeholder="samir/my-shop"
                                value={data.repository}
                                onChange={(e) => setData('repository', e.target.value)}
                                autoFocus
                            />
                            <Input
                                aria-label="Branch"
                                className="w-32"
                                placeholder="default branch"
                                value={data.branch}
                                onChange={(e) => setData('branch', e.target.value)}
                            />
                        </div>
                        <InputError message={errors.repository} />
                        <InputError message={errors.branch} />
                    </Row>

                    <Row label="Cluster">
                        <Select value={data.cluster_id} onValueChange={(value) => setData('cluster_id', value)}>
                            <SelectTrigger className="w-full sm:w-72" aria-label="Cluster">
                                <SelectValue placeholder="Choose a cluster" />
                            </SelectTrigger>
                            <SelectContent>
                                {clusters.map((cluster) => (
                                    <SelectItem key={cluster.id} value={String(cluster.id)}>
                                        {cluster.name}
                                        {cluster.kind === 'local' && ' (dashboard server)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.cluster_id} />
                    </Row>

                    <Row
                        label="Preset"
                        hint="The defaults for the kind of app. You can change each default below. You cannot change the preset later."
                    >
                        <div className="flex flex-wrap gap-2">
                            {presets.map((each) => (
                                <Chip key={each.value} selected={data.type === each.value} onClick={() => changePreset(each)}>
                                    <span className="font-medium">{applicationTypeLabels[each.value]}</span>
                                    <span className="text-muted-foreground block text-xs">{presetText[each.value].hint}</span>
                                </Chip>
                            ))}
                        </div>
                        <InputError message={errors.type} />
                        {preset.chooses_port && (
                            <div className="flex items-center gap-2">
                                <Label htmlFor="port">Port</Label>
                                <Input id="port" type="number" className="w-28" value={data.port} onChange={(e) => setData('port', e.target.value)} />
                            </div>
                        )}
                        <InputError message={errors.port} />
                    </Row>

                    <Row label="Size per instance">
                        <div className="flex flex-wrap gap-2">
                            {sizes.map((size) => (
                                <Chip key={size.value} selected={data.size === size.value} onClick={() => setData('size', size.value)}>
                                    <span className="font-medium">{sizeLabel(size.value)}</span>
                                    <span className="text-muted-foreground block text-xs">{sizeText(size)}</span>
                                </Chip>
                            ))}
                        </div>
                        <InputError message={errors.size} />
                    </Row>

                    <Row label="Autoscale">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Label htmlFor="min_instances">Min</Label>
                            <Input
                                id="min_instances"
                                type="number"
                                min={1}
                                className="w-20"
                                value={data.min_instances}
                                onChange={(e) => setData('min_instances', Number(e.target.value))}
                            />
                            <Label htmlFor="max_instances">max</Label>
                            <Input
                                id="max_instances"
                                type="number"
                                min={1}
                                className="w-20"
                                value={data.max_instances}
                                onChange={(e) => setData('max_instances', Number(e.target.value))}
                            />
                            <span>instances. Add one above</span>
                            <Input
                                aria-label="CPU target"
                                type="number"
                                min={10}
                                max={95}
                                className="w-20"
                                value={data.cpu_target}
                                onChange={(e) => setData('cpu_target', Number(e.target.value))}
                            />
                            <span>% CPU.</span>
                        </div>
                        <InputError message={errors.min_instances} />
                        <InputError message={errors.max_instances} />
                        <InputError message={errors.cpu_target} />
                    </Row>

                    <Row label="Build command" htmlFor="build_command" hint="Optional. One command per line. A # starts a comment.">
                        <ShellEditor
                            id="build_command"
                            rows={2}
                            placeholder={presetText[preset.value].buildCommand}
                            disabled={!preset.uses_build_command}
                            value={data.build_command}
                            onChange={(value) => setData('build_command', value)}
                        />
                        <InputError message={errors.build_command} />
                    </Row>

                    <Row label="Deploy commands" htmlFor="deploy_commands" hint="One per line. They run before the new version takes traffic.">
                        <ShellEditor
                            id="deploy_commands"
                            rows={2}
                            value={data.deploy_commands}
                            onChange={(value) => setData('deploy_commands', value)}
                        />
                        <InputError message={errors.deploy_commands} />
                    </Row>

                    <Row
                        label="Processes"
                        hint="Each runs next to web with the same image, env, and size. A long-running process runs all the time. A scheduled process runs on a cron schedule."
                    >
                        {data.processes.length > 0 && (
                            <ul className="space-y-2">
                                {data.processes.map((row, index) => (
                                    <li key={index}>
                                        <ProcessFields value={row} onChange={(change) => setProcess(index, change)} errors={processErrors(index)}>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setData(
                                                        'processes',
                                                        data.processes.filter((_, i) => i !== index),
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        </ProcessFields>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            onClick={() => setData('processes', [...data.processes, { name: '', kind: 'long_running', command: '', schedule: null }])}
                        >
                            Add a process
                        </Button>
                        <InputError message={errors.processes} />
                    </Row>

                    <Row label="Environment values" hint="A secret is hidden after you save it.">
                        {data.env.length > 0 && (
                            <ul className="space-y-2">
                                {data.env.map((row, index) => (
                                    <li key={index} className="space-y-1">
                                        <div className="flex items-center gap-2">
                                            <Input
                                                aria-label="Key"
                                                className="w-44 font-mono text-sm"
                                                placeholder="KEY"
                                                value={row.key}
                                                onChange={(e) => setEnv(index, { key: e.target.value })}
                                            />
                                            <Input
                                                aria-label="Value"
                                                className="flex-1 font-mono text-sm"
                                                placeholder="value"
                                                type={row.is_secret ? 'password' : 'text'}
                                                autoComplete="off"
                                                value={row.value}
                                                onChange={(e) => setEnv(index, { value: e.target.value })}
                                            />
                                            <Label className="flex items-center gap-1.5 text-sm font-normal">
                                                <Checkbox
                                                    checked={row.is_secret}
                                                    onCheckedChange={(checked) => setEnv(index, { is_secret: checked === true })}
                                                />
                                                Secret
                                            </Label>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setData(
                                                        'env',
                                                        data.env.filter((_, i) => i !== index),
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                        <InputError message={errors[`env.${index}.key` as keyof typeof errors]} />
                                    </li>
                                ))}
                            </ul>
                        )}

                        {pasting && (
                            <div className="space-y-2">
                                <Textarea
                                    aria-label="Contents of a .env file"
                                    className="font-mono text-sm"
                                    rows={6}
                                    placeholder={'APP_ENV=production\nMAIL_MAILER=log'}
                                    value={pasted}
                                    onChange={(e) => setPasted(e.target.value)}
                                />
                                <div className="flex gap-2">
                                    <Button type="button" size="sm" onClick={addPasted}>
                                        Add these values
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => setPasting(false)}>
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        )}

                        {!pasting && (
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => setData('env', [...data.env, { key: '', value: '', is_secret: false }])}
                                >
                                    Add a value
                                </Button>
                                <Button type="button" size="sm" variant="secondary" onClick={() => setPasting(true)}>
                                    Paste a .env file
                                </Button>
                            </div>
                        )}
                    </Row>

                    <Row label="Domain" hint="Optional. You can add it later.">
                        <Input placeholder="shop.example.com" value={data.domain} onChange={(e) => setData('domain', e.target.value)} />
                        <InputError message={errors.domain} />
                    </Row>

                    <Row label="Env names" hint="The names of the values that a database, Valkey, or a bucket sets.">
                        <div className="flex flex-wrap gap-2">
                            {envProfiles.map((profile) => (
                                <Chip
                                    key={profile.value}
                                    selected={data.env_profile === profile.value}
                                    onClick={() => setData('env_profile', profile.value)}
                                >
                                    <span className="font-medium">{profile.label}</span>
                                    <span className="text-muted-foreground block font-mono text-xs">{profile.hint}</span>
                                </Chip>
                            ))}
                        </div>
                        <InputError message={errors.env_profile} />
                    </Row>

                    <Row label="Database" hint="opencloud creates it and sets its env values.">
                        <Select value={data.database} onValueChange={(value) => setData('database', value as Database)}>
                            <SelectTrigger className="w-full sm:w-72" aria-label="Database">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="mysql">MySQL</SelectItem>
                                <SelectItem value="postgres">Postgres</SelectItem>
                                <SelectItem value="none">None</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.database} />
                    </Row>
                </div>

                <div className="mt-6 flex items-center gap-3">
                    <Button disabled={processing || data.repository.trim() === ''}>{processing ? 'Creating' : 'Create and deploy'}</Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('applications.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
