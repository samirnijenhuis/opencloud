import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { applicationTypeLabels, type ApplicationType, type EnvProfileKind } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Chip, envProfiles } from './presets';

type Saved = { id: number; type: ApplicationType; env_profile: EnvProfileKind };

/** Shows the preset as a fixed fact and changes the env names of an application that exists. */
export function EnvProfileForm({ application, canUpdate }: { application: Saved; canUpdate: boolean }) {
    const form = useForm({ env_profile: application.env_profile });
    const { data, setData, errors, processing, recentlySuccessful, isDirty } = form;
    const names = (kind: EnvProfileKind) => envProfiles.find((profile) => profile.value === kind)?.hint;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('applications.env-profile.update', application.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <form onSubmit={submit} className="divide-y rounded-lg border">
            <div className="space-y-1 p-4">
                <Label>Preset</Label>
                <p className="font-medium">{applicationTypeLabels[application.type]}</p>
                <p className="text-muted-foreground text-sm">To use another preset, create a new application with the same repository.</p>
            </div>

            <fieldset disabled={!canUpdate} className="space-y-2 p-4">
                <Label>Env names</Label>
                <div className="flex flex-wrap gap-2">
                    {envProfiles.map((profile) => (
                        <Chip key={profile.value} selected={data.env_profile === profile.value} onClick={() => setData('env_profile', profile.value)}>
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
            </fieldset>

            {canUpdate && (
                <div className="flex flex-wrap items-center justify-end gap-3 p-4">
                    <span className="text-muted-foreground mr-auto text-xs">The pods get the new names on the next deploy.</span>
                    {recentlySuccessful && <span className="text-muted-foreground text-sm">Saved</span>}
                    <Button disabled={!isDirty || processing}>Save</Button>
                </div>
            )}
        </form>
    );
}
