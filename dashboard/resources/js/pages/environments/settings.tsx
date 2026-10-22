import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import EnvironmentLayout from '@/layouts/environment-layout';
import { sizeLabel, sizeText } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type EnvironmentPageProps, type Size, type SizeOption } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Settings = {
    branch: string;
    auto_deploy: boolean;
    size: Size;
    min_instances: number;
    max_instances: number;
    cpu_target: number;
    health_path: string;
    build_command: string | null;
    deploy_commands: string[];
    worker_enabled: boolean;
    scheduler_enabled: boolean;
};

type Props = EnvironmentPageProps & { settings: Settings; sizes: SizeOption[] };

export default function EnvironmentSettings(props: Props) {
    const { environment, application, settings, sizes, can } = props;
    const form = useForm({
        ...settings,
        build_command: settings.build_command ?? '',
        deploy_commands: settings.deploy_commands.join('\n'),
    });
    const { data, setData, errors, processing } = form;
    const locked = !can.update;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((current) => ({ ...current, deploy_commands: current.deploy_commands.split('\n') }));
        form.patch(route('environments.update', environment.id), { preserveScroll: true });
    };

    return (
        <EnvironmentLayout page={props} tab="settings" title="Settings">
            <form onSubmit={submit} className="max-w-2xl space-y-6">
                <p className="text-muted-foreground text-sm">Changes apply on the next deploy.</p>
                {locked && <p className="text-sm">Only an admin or an owner can change a protected environment.</p>}

                <fieldset disabled={locked} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="branch">Branch</Label>
                        <Input id="branch" className="max-w-xs" value={data.branch} onChange={(e) => setData('branch', e.target.value)} />
                        <InputError message={errors.branch} />
                    </div>

                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox checked={data.auto_deploy} onCheckedChange={(checked) => setData('auto_deploy', checked === true)} />
                        Deploy automatically when the branch changes
                    </Label>

                    <div className="grid gap-2">
                        <Label>Size per instance</Label>
                        <div className="flex flex-wrap gap-2">
                            {sizes.map((size) => (
                                <button
                                    key={size.value}
                                    type="button"
                                    aria-pressed={data.size === size.value}
                                    onClick={() => setData('size', size.value)}
                                    className={cn(
                                        'rounded-md border px-3 py-2 text-left text-sm disabled:opacity-60',
                                        data.size === size.value ? 'border-primary bg-primary/5 ring-primary ring-1' : 'enabled:hover:bg-muted',
                                    )}
                                    disabled={locked}
                                >
                                    <span className="font-medium">{sizeLabel(size.value)}</span>
                                    <span className="text-muted-foreground block text-xs">{sizeText(size)}</span>
                                </button>
                            ))}
                        </div>
                        <InputError message={errors.size} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
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
                        <div className="grid gap-2">
                            <Label htmlFor="cpu_target">CPU target (%)</Label>
                            <Input
                                id="cpu_target"
                                type="number"
                                min={10}
                                max={95}
                                value={data.cpu_target}
                                onChange={(e) => setData('cpu_target', Number(e.target.value))}
                            />
                            <InputError message={errors.cpu_target} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="health_path">Health path</Label>
                        <Input
                            id="health_path"
                            className="max-w-xs font-mono"
                            value={data.health_path}
                            onChange={(e) => setData('health_path', e.target.value)}
                        />
                        <InputError message={errors.health_path} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="build_command">Build command</Label>
                        <Input
                            id="build_command"
                            className="font-mono text-sm"
                            value={data.build_command}
                            onChange={(e) => setData('build_command', e.target.value)}
                        />
                        <InputError message={errors.build_command} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="deploy_commands">Deploy commands</Label>
                        <Textarea
                            id="deploy_commands"
                            className="font-mono text-sm"
                            rows={3}
                            value={data.deploy_commands}
                            onChange={(e) => setData('deploy_commands', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">One command per line.</p>
                        <InputError message={errors.deploy_commands} />
                    </div>

                    {application.type === 'laravel' && (
                        <div className="flex flex-wrap gap-6">
                            <Label className="flex items-center gap-2 font-normal">
                                <Checkbox checked={data.worker_enabled} onCheckedChange={(checked) => setData('worker_enabled', checked === true)} />
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
                    )}
                </fieldset>

                {!locked && (
                    <div className="flex items-center gap-3">
                        <Button disabled={processing}>Save settings</Button>
                        {form.recentlySuccessful && (
                            <span className="text-muted-foreground text-sm">Saved. The change applies on the next deploy.</span>
                        )}
                    </div>
                )}
            </form>
        </EnvironmentLayout>
    );
}
