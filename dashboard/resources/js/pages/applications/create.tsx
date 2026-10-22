import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { parseDotenv, sizeLabel, sizeText } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Size, type SizeOption } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type Cluster = { id: number; name: string; kind: string; ingress_ip: string | null };
type EnvRow = { key: string; value: string; is_secret: boolean };

type FormData = {
    repository: string;
    branch: string;
    cluster_id: string;
    type: 'laravel' | 'dockerfile';
    port: string;
    size: Size;
    min_instances: number;
    max_instances: number;
    cpu_target: number;
    build_command: string;
    deploy_commands: string;
    worker_enabled: boolean;
    scheduler_enabled: boolean;
    env: EnvRow[];
    domain: string;
    database: 'mysql' | 'postgres' | 'none';
};

const looksSecret = (key: string) => /(SECRET|PASSWORD|TOKEN|KEY|AUTH)/i.test(key);

function Row({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-2 border-b py-5 sm:grid-cols-[11rem_1fr] sm:gap-6">
            <div>
                <Label className="text-sm font-medium">{label}</Label>
                {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
            </div>
            <div className="min-w-0 space-y-2">{children}</div>
        </div>
    );
}

function Chip({ selected, onClick, children }: { selected: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={cn(
                'rounded-md border px-3 py-2 text-left text-sm transition-colors',
                selected ? 'border-primary bg-primary/5 ring-primary ring-1' : 'hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

export default function ApplicationCreate({
    clusters,
    defaultClusterId,
    sizes,
}: {
    clusters: Cluster[];
    defaultClusterId: number | null;
    sizes: SizeOption[];
}) {
    const form = useForm<FormData>({
        repository: '',
        branch: 'main',
        cluster_id: defaultClusterId ? String(defaultClusterId) : '',
        type: 'laravel',
        port: '8080',
        size: 's',
        min_instances: 1,
        max_instances: 1,
        cpu_target: 70,
        build_command: '',
        deploy_commands: 'php artisan migrate --force',
        worker_enabled: true,
        scheduler_enabled: true,
        env: [],
        domain: '',
        database: 'mysql',
    });
    const { data, setData, errors, processing } = form;
    const [pasting, setPasting] = useState(false);
    const [pasted, setPasted] = useState('');
    const laravel = data.type === 'laravel';

    const changeType = (type: FormData['type']) => {
        setData((current) => ({
            ...current,
            type,
            deploy_commands: type === 'laravel' ? 'php artisan migrate --force' : '',
            database: type === 'laravel' ? 'mysql' : 'none',
        }));
    };

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
            port: laravel ? null : current.port,
            deploy_commands: current.deploy_commands.split('\n'),
            env: current.env.filter((row) => row.key.trim() !== ''),
            worker_enabled: laravel && current.worker_enabled,
            scheduler_enabled: laravel && current.scheduler_enabled,
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
                            <Input aria-label="Branch" className="w-32" value={data.branch} onChange={(e) => setData('branch', e.target.value)} />
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

                    <Row label="Type">
                        <div className="flex flex-wrap gap-2">
                            <Chip selected={laravel} onClick={() => changeType('laravel')}>
                                Laravel
                            </Chip>
                            <Chip selected={!laravel} onClick={() => changeType('dockerfile')}>
                                Dockerfile
                            </Chip>
                        </div>
                        {!laravel && (
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

                    <Row label="Build command" hint="Optional">
                        <Input
                            className="font-mono text-sm"
                            placeholder={
                                laravel
                                    ? 'Optional extra step after the build, for example php artisan icons:cache'
                                    : 'Not used. The Dockerfile builds the image.'
                            }
                            value={data.build_command}
                            onChange={(e) => setData('build_command', e.target.value)}
                        />
                        <InputError message={errors.build_command} />
                    </Row>

                    <Row label="Deploy commands" hint="One per line. They run before the new version takes traffic.">
                        <Textarea
                            className="font-mono text-sm"
                            rows={2}
                            value={data.deploy_commands}
                            onChange={(e) => setData('deploy_commands', e.target.value)}
                        />
                        <InputError message={errors.deploy_commands} />
                    </Row>

                    {laravel && (
                        <Row label="Processes">
                            <div className="flex flex-wrap gap-6">
                                <Label className="flex items-center gap-2 font-normal">
                                    <Checkbox
                                        checked={data.worker_enabled}
                                        onCheckedChange={(checked) => setData('worker_enabled', checked === true)}
                                    />
                                    Queue worker
                                </Label>
                                <Label className="flex items-center gap-2 font-normal">
                                    <Checkbox
                                        checked={data.scheduler_enabled}
                                        onCheckedChange={(checked) => setData('scheduler_enabled', checked === true)}
                                    />
                                    Scheduler
                                </Label>
                            </div>
                        </Row>
                    )}

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

                    {laravel && (
                        <Row label="Database" hint="opencloud creates it and sets the DB_ values.">
                            <Select value={data.database} onValueChange={(value) => setData('database', value as FormData['database'])}>
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
                    )}
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
