import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Process, type ProcessKind, type Size, type SizeOption } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import { Choices, ScalingFields, SizePicker } from './compute-fields';
import { EditorSheet, NextDeployFooter, SheetTabs } from './editor-sheet';
import { type AppTab, type Compute } from './types';

/** The sheet holds the values of both kinds, so a switch of the kind keeps them. The server drops the values that the kind does not use. */
type ProcessForm = {
    name: string;
    kind: ProcessKind;
    command: string;
    schedule: string;
    size: Size;
    min_instances: number;
    max_instances: number;
    cpu_target: number;
};

const EVERY_MINUTE = '* * * * *';

const AUTOSCALING_FIELDS: (keyof ProcessForm)[] = ['min_instances', 'max_instances', 'cpu_target'];

const LOCKED_REASON = 'Only an admin or an owner can change a protected environment.';

const kindOptions: { value: ProcessKind; label: string; text: string }[] = [
    {
        value: 'long_running',
        label: 'Long-running',
        text: 'Runs all the time, for example a queue worker. The Laravel scheduler runs here with php artisan schedule:work.',
    },
    {
        value: 'scheduled',
        label: 'Scheduled',
        text: 'Starts a new pod for each run, so the command must end. A task that it starts in the background ends with the pod.',
    },
];

const descriptions: Record<ProcessKind, string> = {
    long_running: 'A long-running process runs all the time with the image and the env values of web.',
    scheduled: 'A scheduled process starts one pod on each time of its schedule. The pod stops when the command ends.',
};

/** The schedule in words, or null when opencloud has no words for the expression. */
export const scheduleWords = (schedule: string) => (schedule.trim() === EVERY_MINUTE ? 'Every minute' : null);

/** A new process starts with the size and the CPU target of web and 1 instance. */
function initialValues(process: Process | undefined, compute: Compute): ProcessForm {
    const blank: ProcessForm = {
        name: '',
        kind: 'long_running',
        command: '',
        schedule: EVERY_MINUTE,
        size: compute.size,
        min_instances: 1,
        max_instances: 1,
        cpu_target: compute.cpu_target,
    };

    if (!process) {
        return blank;
    }

    const shared = { ...blank, name: process.name, kind: process.kind, command: process.command, size: process.size };

    return process.kind === 'scheduled'
        ? { ...shared, schedule: process.schedule }
        : { ...shared, min_instances: process.min_instances, max_instances: process.max_instances, cpu_target: process.cpu_target };
}

export function ProcessSheet({
    open,
    onClose,
    tab,
    onTab,
    environmentId,
    process,
    compute,
    sizes,
    canUpdate,
    canDeploy,
}: {
    open: boolean;
    onClose: () => void;
    tab: AppTab;
    onTab: (tab: AppTab) => void;
    environmentId: number;
    process: Process | undefined;
    compute: Compute;
    sizes: SizeOption[];
    canUpdate: boolean;
    canDeploy: boolean;
}) {
    const form = useForm<ProcessForm>(initialValues(process, compute));
    const { data, setData, errors } = form;
    const [deleting, setDeleting] = useState(false);
    const locked = !canUpdate;
    const scheduled = data.kind === 'scheduled';
    const shown: AppTab = scheduled ? 'general' : tab;

    useEffect(() => {
        if (open) {
            const values = initialValues(process, compute);
            form.setDefaults(values);
            form.setData(values);
            form.clearErrors();
        }
        // The form starts again from the saved values each time the sheet opens or shows another process.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, process?.id]);

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onError: (failed: Record<string, string>) =>
                onTab(AUTOSCALING_FIELDS.includes(Object.keys(failed)[0] as keyof ProcessForm) ? 'autoscaling' : 'general'),
        };

        if (process) {
            form.patch(route('processes.update', process.id), { ...options, onSuccess: () => form.setDefaults() });
        } else {
            form.post(route('processes.store', environmentId), { ...options, onSuccess: onClose });
        }
    };

    const remove = () => {
        if (process) {
            router.delete(route('processes.destroy', process.id), {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleting(false);
                    onClose();
                },
            });
        }
    };

    return (
        <>
            <EditorSheet
                open={open}
                onClose={onClose}
                title={process ? process.name : 'New process'}
                description={descriptions[data.kind]}
                tabs={
                    <SheetTabs
                        value={shown}
                        onChange={onTab}
                        items={[
                            { value: 'general', label: 'General' },
                            ...(scheduled ? [] : [{ value: 'autoscaling' as const, label: 'Autoscaling' }]),
                        ]}
                    />
                }
                footer={
                    <NextDeployFooter environmentId={environmentId} canDeploy={canDeploy && process !== undefined}>
                        {canUpdate && (
                            <Button form="process-settings" disabled={form.processing || (process !== undefined && !form.isDirty)}>
                                {process ? (form.recentlySuccessful ? 'Saved' : 'Save') : 'Add process'}
                            </Button>
                        )}
                    </NextDeployFooter>
                }
            >
                {locked && <p className="text-muted-foreground text-sm">{LOCKED_REASON}</p>}

                <form id="process-settings" onSubmit={save}>
                    <fieldset disabled={locked} className="space-y-6">
                        {shown === 'general' && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="process-name">Name</Label>
                                    <p className="text-muted-foreground text-sm">Lowercase letters, digits, and dashes. The logs show this name.</p>
                                    <Input
                                        id="process-name"
                                        className="font-mono"
                                        placeholder="worker"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Kind</Label>
                                    <Choices options={kindOptions} value={data.kind} onChange={(kind) => setData('kind', kind)} />
                                    <InputError message={errors.kind} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="process-command">Command</Label>
                                    <p className="text-muted-foreground text-sm">
                                        {scheduled
                                            ? 'Each run starts a new pod, so the command must end, for example php artisan app:cleanup.'
                                            : 'The command runs in a shell, for example php artisan queue:work.'}
                                    </p>
                                    <Input
                                        id="process-command"
                                        className="font-mono"
                                        placeholder={scheduled ? 'php artisan app:cleanup' : 'php artisan queue:work'}
                                        value={data.command}
                                        onChange={(e) => setData('command', e.target.value)}
                                    />
                                    <InputError message={errors.command} />
                                </div>

                                {scheduled && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="process-schedule">Schedule</Label>
                                        <p className="text-muted-foreground text-sm">
                                            A cron expression with five fields. {EVERY_MINUTE} runs the command each minute.
                                        </p>
                                        <Input
                                            id="process-schedule"
                                            className="font-mono"
                                            placeholder={EVERY_MINUTE}
                                            value={data.schedule}
                                            onChange={(e) => setData('schedule', e.target.value)}
                                        />
                                        <InputError message={errors.schedule} />
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label>Size</Label>
                                    <p className="text-muted-foreground text-sm">
                                        {scheduled
                                            ? 'The pod of each run gets this much CPU and memory.'
                                            : 'Each instance gets this much CPU and memory.'}
                                    </p>
                                    <SizePicker sizes={sizes} value={data.size} onChange={(size) => setData('size', size)} />
                                    <InputError message={errors.size} />
                                </div>
                            </>
                        )}

                        {shown === 'autoscaling' && (
                            <>
                                <p className="text-muted-foreground text-sm">
                                    The process scales between the minimum and the maximum to keep the CPU near the target. With the same minimum and
                                    maximum, that many instances run all the time.
                                </p>
                                <ScalingFields value={data} onChange={(field, value) => setData(field, value)} errors={errors} />
                            </>
                        )}
                    </fieldset>
                </form>

                {process && canUpdate && shown === 'general' && (
                    <section className="space-y-2 border-t pt-5">
                        <h3 className="text-sm font-medium">Delete</h3>
                        <p className="text-muted-foreground text-sm">The next deploy stops its pods.</p>
                        <Button variant="destructive" size="sm" onClick={() => setDeleting(true)}>
                            Delete {process.name}
                        </Button>
                    </section>
                )}
            </EditorSheet>

            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {process?.name}?</DialogTitle>
                        <DialogDescription>Opencloud removes the process now. The next deploy stops its pods.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setDeleting(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={remove}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
