import InputError from '@/components/input-error';
import ProcessFields from '@/components/process-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { sizeLabel, sizeText } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Process, type ProcessValues, type SizeOption } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';
import { EditorSheet, NextDeployFooter, SheetTabs } from './editor-sheet';
import { type AppTab, type Compute } from './types';

const tabFields: Record<Exclude<AppTab, 'processes'>, (keyof Compute)[]> = {
    general: ['size', 'health_path'],
    autoscaling: ['min_instances', 'max_instances', 'cpu_target'],
};

export const LOCKED_REASON = 'Only an admin or an owner can change a protected environment.';

function ProcessRow({ process, locked }: { process: Process; locked: boolean }) {
    const form = useForm<ProcessValues>({ name: process.name, command: process.command, instances: process.instances, exclusive: process.exclusive });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('processes.update', process.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const remove = () => {
        if (confirm(`Remove ${process.name}? Its pods stop on the next deploy.`)) {
            router.delete(route('processes.destroy', process.id), { preserveScroll: true });
        }
    };

    return (
        <li className="rounded-lg border p-3">
            <form onSubmit={save}>
                <ProcessFields
                    value={form.data}
                    onChange={(change) => form.setData({ ...form.data, ...change })}
                    errors={form.errors}
                    disabled={locked}
                >
                    {!locked && (
                        <span className="ml-auto flex gap-1">
                            <Button size="sm" variant="secondary" disabled={!form.isDirty || form.processing}>
                                Save
                            </Button>
                            <Button type="button" size="sm" variant="ghost" onClick={remove}>
                                Remove
                            </Button>
                        </span>
                    )}
                </ProcessFields>
            </form>
        </li>
    );
}

function NewProcess({ environmentId }: { environmentId: number }) {
    const form = useForm<ProcessValues>({ name: '', command: '', instances: 1, exclusive: false });

    const add: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('processes.store', environmentId), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={add} className="space-y-2 rounded-lg border border-dashed p-3">
            <p className="text-sm font-medium">New process</p>
            <ProcessFields value={form.data} onChange={(change) => form.setData({ ...form.data, ...change })} errors={form.errors}>
                <Button size="sm" className="ml-auto" disabled={form.processing}>
                    Add process
                </Button>
            </ProcessFields>
        </form>
    );
}

export function AppSheet({
    open,
    onClose,
    tab,
    onTab,
    environmentId,
    clusterName,
    compute,
    sizes,
    processes,
    canUpdate,
    canDeploy,
}: {
    open: boolean;
    onClose: () => void;
    tab: AppTab;
    onTab: (tab: AppTab) => void;
    environmentId: number;
    clusterName: string;
    compute: Compute;
    sizes: SizeOption[];
    processes: Process[];
    canUpdate: boolean;
    canDeploy: boolean;
}) {
    const form = useForm<Compute>(compute);
    const { data, setData, errors } = form;
    const locked = !canUpdate;

    useEffect(() => {
        if (open) {
            form.setDefaults(compute);
            form.setData(compute);
            form.clearErrors();
        }
        // The form starts again from the saved values each time the sheet opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('environments.update', environmentId), {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
            onError: (failed) => {
                const field = Object.keys(failed)[0] as keyof Compute;
                onTab(tabFields.autoscaling.includes(field) ? 'autoscaling' : 'general');
            },
        });
    };

    return (
        <EditorSheet
            open={open}
            onClose={onClose}
            title="App"
            description={`The processes that run your code on ${clusterName}.`}
            tabs={
                <SheetTabs
                    value={tab}
                    onChange={onTab}
                    items={[
                        { value: 'general', label: 'General' },
                        { value: 'autoscaling', label: 'Autoscaling' },
                        { value: 'processes', label: 'Processes' },
                    ]}
                />
            }
            footer={
                <NextDeployFooter environmentId={environmentId} canDeploy={canDeploy}>
                    {tab !== 'processes' && canUpdate && (
                        <Button form="app-settings" disabled={!form.isDirty || form.processing}>
                            {form.recentlySuccessful ? 'Saved' : 'Save'}
                        </Button>
                    )}
                </NextDeployFooter>
            }
        >
            {locked && <p className="text-muted-foreground text-sm">{LOCKED_REASON}</p>}

            {tab !== 'processes' && (
                <form id="app-settings" onSubmit={save}>
                    <fieldset disabled={locked} className="space-y-6">
                        {tab === 'general' && (
                            <>
                                <div className="grid gap-2">
                                    <Label>Size per instance</Label>
                                    <p className="text-muted-foreground text-sm">Web and each process get this much CPU and memory.</p>
                                    <div className="grid grid-cols-2 gap-2">
                                        {sizes.map((size) => (
                                            <button
                                                key={size.value}
                                                type="button"
                                                aria-pressed={data.size === size.value}
                                                onClick={() => setData('size', size.value)}
                                                className={cn(
                                                    'rounded-md border px-3 py-2 text-left text-sm disabled:opacity-60',
                                                    data.size === size.value ? 'border-foreground ring-foreground ring-1' : 'enabled:hover:bg-muted',
                                                )}
                                            >
                                                <span className="font-medium">{sizeLabel(size.value)}</span>
                                                <span className="text-muted-foreground block text-xs">{sizeText(size)}</span>
                                            </button>
                                        ))}
                                    </div>
                                    <InputError message={errors.size} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="health_path">Health check path</Label>
                                    <p className="text-muted-foreground text-sm">
                                        An instance gets traffic only when this path answers with a 2xx or 3xx status.
                                    </p>
                                    <Input
                                        id="health_path"
                                        className="font-mono"
                                        value={data.health_path}
                                        onChange={(e) => setData('health_path', e.target.value)}
                                    />
                                    <InputError message={errors.health_path} />
                                </div>
                            </>
                        )}

                        {tab === 'autoscaling' && (
                            <>
                                <p className="text-muted-foreground text-sm">
                                    Web scales between the minimum and the maximum to keep the CPU near the target. With the same minimum and maximum,
                                    that many instances run all the time.
                                </p>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="min_instances">Minimum instances</Label>
                                        <Input
                                            id="min_instances"
                                            type="number"
                                            min={1}
                                            value={data.min_instances}
                                            onChange={(e) => setData('min_instances', Number(e.target.value))}
                                        />
                                        <InputError message={errors.min_instances} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="max_instances">Maximum instances</Label>
                                        <Input
                                            id="max_instances"
                                            type="number"
                                            min={1}
                                            value={data.max_instances}
                                            onChange={(e) => setData('max_instances', Number(e.target.value))}
                                        />
                                        <InputError message={errors.max_instances} />
                                    </div>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="cpu_target">CPU target (%)</Label>
                                    <Input
                                        id="cpu_target"
                                        type="number"
                                        min={10}
                                        max={95}
                                        className="w-32"
                                        value={data.cpu_target}
                                        onChange={(e) => setData('cpu_target', Number(e.target.value))}
                                    />
                                    <InputError message={errors.cpu_target} />
                                </div>
                            </>
                        )}
                    </fieldset>
                </form>
            )}

            {tab === 'processes' && (
                <div className="space-y-3">
                    <p className="text-muted-foreground text-sm">
                        Web serves HTTP and uses the instances of the app. Each process here runs next to web with the same image, env values, and
                        size. An exclusive process runs exactly one copy, for example the scheduler.
                    </p>
                    <ul className="space-y-2">
                        {processes.map((process) => (
                            <ProcessRow key={process.id} process={process} locked={locked} />
                        ))}
                    </ul>
                    {processes.length === 0 && <p className="text-sm">Only web runs.</p>}
                    {!locked && <NewProcess environmentId={environmentId} />}
                </div>
            )}
        </EditorSheet>
    );
}
