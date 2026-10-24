import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import EnvironmentLayout from '@/layouts/environment-layout';
import { type EnvironmentPageProps } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Settings = {
    branch: string;
    auto_deploy: boolean;
    build_command: string | null;
    deploy_commands: string[];
};

type Props = EnvironmentPageProps & { settings: Settings };

export default function EnvironmentSettings(props: Props) {
    const { environment, settings, can } = props;
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
