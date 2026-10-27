import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type SizeOption } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';
import { ScalingFields, SizePicker } from './compute-fields';
import { EditorSheet, NextDeployFooter, SheetTabs } from './editor-sheet';
import { type AppTab, type Compute } from './types';

const tabFields: Record<AppTab, (keyof Compute)[]> = {
    general: ['size', 'health_path'],
    autoscaling: ['min_instances', 'max_instances', 'cpu_target'],
};

const LOCKED_REASON = 'Only an admin or an owner can change a protected environment.';

export function AppSheet({
    open,
    onClose,
    tab,
    onTab,
    environmentId,
    clusterName,
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
    clusterName: string;
    compute: Compute;
    sizes: SizeOption[];
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
            description={`Web serves HTTP for your code on ${clusterName}.`}
            tabs={
                <SheetTabs
                    value={tab}
                    onChange={onTab}
                    items={[
                        { value: 'general', label: 'General' },
                        { value: 'autoscaling', label: 'Autoscaling' },
                    ]}
                />
            }
            footer={
                <NextDeployFooter environmentId={environmentId} canDeploy={canDeploy}>
                    {canUpdate && (
                        <Button form="app-settings" disabled={!form.isDirty || form.processing}>
                            {form.recentlySuccessful ? 'Saved' : 'Save'}
                        </Button>
                    )}
                </NextDeployFooter>
            }
        >
            {locked && <p className="text-muted-foreground text-sm">{LOCKED_REASON}</p>}

            <form id="app-settings" onSubmit={save}>
                <fieldset disabled={locked} className="space-y-6">
                    {tab === 'general' && (
                        <>
                            <div className="grid gap-2">
                                <Label>Size per instance</Label>
                                <p className="text-muted-foreground text-sm">Each instance of web gets this much CPU and memory.</p>
                                <SizePicker sizes={sizes} value={data.size} onChange={(size) => setData('size', size)} />
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
                            <ScalingFields value={data} onChange={(field, value) => setData(field, value)} errors={errors} />
                        </>
                    )}
                </fieldset>
            </form>
        </EditorSheet>
    );
}
