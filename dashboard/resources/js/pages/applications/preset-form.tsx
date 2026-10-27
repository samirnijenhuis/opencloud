import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { applicationTypeLabels, type ApplicationType, type EnvProfileKind } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Chip, envProfiles, presetText, type Preset } from './presets';

type Saved = { id: number; type: ApplicationType; env_profile: EnvProfileKind; port: number };

/** Changes the preset and the env names of an application that exists. The processes and the commands stay. */
export function PresetForm({
    application,
    presets,
    productionId,
    canUpdate,
}: {
    application: Saved;
    presets: Preset[];
    productionId: number | null;
    canUpdate: boolean;
}) {
    const form = useForm({ type: application.type, env_profile: application.env_profile, port: String(application.port) });
    const { data, setData, errors, processing, recentlySuccessful, isDirty } = form;
    const preset = presets.find((each) => each.value === data.type) ?? presets[0];
    const names = (kind: EnvProfileKind) => envProfiles.find((profile) => profile.value === kind)?.hint;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((current) => ({ ...current, port: preset.chooses_port && current.port.trim() !== '' ? Number(current.port) : null }));
        form.patch(route('applications.preset.update', application.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <form onSubmit={submit} className="divide-y rounded-lg border">
            <fieldset disabled={!canUpdate} className="space-y-5 p-4">
                <div className="space-y-2">
                    <Label>Preset</Label>
                    <div className="flex flex-wrap gap-2">
                        {presets.map((each) => (
                            <Chip
                                key={each.value}
                                selected={data.type === each.value}
                                onClick={() => setData((current) => ({ ...current, type: each.value, env_profile: each.env_profile }))}
                            >
                                <span className="font-medium">{applicationTypeLabels[each.value]}</span>
                                <span className="text-muted-foreground block text-xs">{presetText[each.value].hint}</span>
                            </Chip>
                        ))}
                    </div>
                    <InputError message={errors.type} />
                    {preset.chooses_port ? (
                        <div className="flex items-center gap-2">
                            <Label htmlFor="port">Port</Label>
                            <Input id="port" type="number" className="w-28" value={data.port} onChange={(e) => setData('port', e.target.value)} />
                        </div>
                    ) : (
                        application.port !== preset.port && (
                            <p className="text-muted-foreground text-sm">
                                The port changes from {application.port} to {preset.port}, because the {applicationTypeLabels[preset.value]} image
                                always listens on {preset.port}.
                            </p>
                        )
                    )}
                    <InputError message={errors.port} />
                </div>

                <div className="space-y-2">
                    <Label>Env names</Label>
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
                    {data.env_profile !== application.env_profile && (
                        <p className="bg-muted rounded-md px-3 py-2 text-sm">
                            The databases, Valkey, and the buckets of every environment then set{' '}
                            <code className="font-mono text-xs">{names(data.env_profile)}</code> in place of{' '}
                            <code className="font-mono text-xs">{names(application.env_profile)}</code>.
                        </p>
                    )}
                </div>

                <p className="text-muted-foreground text-sm">
                    The processes, the deploy commands, the build command, and the health check path stay as they are.{' '}
                    {productionId !== null && (
                        <>
                            Change the processes and the health check path in the App panel of an environment, for example{' '}
                            <Link href={route('environments.show', productionId)} className="text-foreground font-medium hover:underline">
                                production
                            </Link>
                            . Change the commands on the{' '}
                            <Link
                                href={`${route('environments.settings', productionId)}#deployments`}
                                className="text-foreground font-medium hover:underline"
                            >
                                Settings page
                            </Link>{' '}
                            of an environment.
                        </>
                    )}
                </p>
            </fieldset>

            {canUpdate && (
                <div className="flex flex-wrap items-center justify-end gap-3 p-4">
                    <span className="text-muted-foreground mr-auto text-xs">The pods get the changes on the next deploy.</span>
                    {recentlySuccessful && <span className="text-muted-foreground text-sm">Saved</span>}
                    <Button disabled={!isDirty || processing}>Save</Button>
                </div>
            )}
        </form>
    );
}
