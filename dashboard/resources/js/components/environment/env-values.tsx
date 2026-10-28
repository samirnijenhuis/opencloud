import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Availability } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import { EnvTextEditor } from './env-text-editor';

export type EnvVar = {
    id: number;
    key: string;
    source: 'user' | 'datastore';
    is_secret: boolean;
    available_at: Availability;
    resource: string | null;
    value: string | null;
};

const availabilityLabels: Record<Availability, string> = {
    runtime: 'Runtime',
    build: 'Build',
    both: 'Build and runtime',
};

type Mode = 'table' | 'text';

const MODE_KEY = 'opencloud.env-mode';

function savedMode(): Mode {
    try {
        return localStorage.getItem(MODE_KEY) === 'text' ? 'text' : 'table';
    } catch {
        return 'table';
    }
}

export function EnvValues({
    environmentId,
    envVars,
    envText,
    canUpdate,
}: {
    environmentId: number;
    envVars: EnvVar[];
    envText: string;
    canUpdate: boolean;
}) {
    const [mode, setMode] = useState<Mode>('table');

    // The saved mode loads after the first render, so the server and the browser render the same page.
    useEffect(() => setMode(savedMode()), []);

    const choose = (next: Mode) => {
        setMode(next);
        try {
            localStorage.setItem(MODE_KEY, next);
        } catch {
            // The mode is a convenience. The page works without it.
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex gap-1" role="group" aria-label="Edit mode">
                {(['table', 'text'] as const).map((value) => (
                    <Button
                        key={value}
                        type="button"
                        size="sm"
                        variant={mode === value ? 'secondary' : 'ghost'}
                        aria-pressed={mode === value}
                        onClick={() => choose(value)}
                    >
                        {value === 'table' ? 'Table' : 'Text'}
                    </Button>
                ))}
            </div>
            {mode === 'table' ? (
                <EnvTable environmentId={environmentId} envVars={envVars} canUpdate={canUpdate} />
            ) : (
                <EnvTextEditor environmentId={environmentId} envVars={envVars} envText={envText} canUpdate={canUpdate} />
            )}
        </div>
    );
}

function EnvTable({ environmentId, envVars, canUpdate }: { environmentId: number; envVars: EnvVar[]; canUpdate: boolean }) {
    const form = useForm<{ key: string; value: string; is_secret: boolean; available_at: Availability }>({
        key: '',
        value: '',
        is_secret: false,
        available_at: 'runtime',
    });
    const { data, setData, errors, processing } = form;
    const pageErrors = usePage().props.errors as Record<string, string>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('env-vars.store', environmentId), { preserveScroll: true, onSuccess: () => form.reset('key', 'value', 'is_secret') });
    };

    const replace = (envVar: EnvVar) => {
        form.setData({ key: envVar.key, value: '', is_secret: envVar.is_secret, available_at: envVar.available_at });
        document.getElementById('env-key')?.focus();
    };

    const remove = (envVar: EnvVar) => {
        if (confirm(`Delete ${envVar.key}? The change applies on the next deploy.`)) {
            router.delete(route('env-vars.destroy', envVar.id), { preserveScroll: true });
        }
    };

    const existing = envVars.find((envVar) => envVar.source === 'user' && envVar.key === data.key);

    return (
        <div className="space-y-4">
            {envVars.length === 0 ? (
                <p className="text-sm">This environment has no values yet.</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[36rem] text-sm">
                        <thead className="text-muted-foreground border-b text-left">
                            <tr>
                                <th className="p-3 font-medium">Key</th>
                                <th className="p-3 font-medium">Value</th>
                                <th className="p-3 font-medium">Available at</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {envVars.map((envVar) => (
                                <tr key={envVar.id}>
                                    <td className="p-3 font-mono">{envVar.key}</td>
                                    <td className="p-3">
                                        {envVar.source === 'datastore' ? (
                                            <span className="text-muted-foreground">Set by {envVar.resource ?? 'a resource'}. Read only.</span>
                                        ) : envVar.is_secret ? (
                                            <Badge variant="secondary">Secret, set</Badge>
                                        ) : (
                                            <code className="font-mono break-all">{envVar.value}</code>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground p-3">{availabilityLabels[envVar.available_at]}</td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {envVar.source === 'user' && canUpdate && (
                                            <>
                                                <Button variant="ghost" size="sm" onClick={() => replace(envVar)}>
                                                    {envVar.is_secret ? 'Replace' : 'Edit'}
                                                </Button>
                                                <Button variant="ghost" size="sm" onClick={() => remove(envVar)}>
                                                    Delete
                                                </Button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {!errors.key && <InputError message={pageErrors.key} />}

            {canUpdate ? (
                <form onSubmit={submit} className="space-y-4 rounded-lg border p-4">
                    <h3 className="text-sm font-medium">{existing ? `Replace ${existing.key}` : 'Add or replace a value'}</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="env-key">Key</Label>
                            <Input
                                id="env-key"
                                className="font-mono"
                                value={data.key}
                                onChange={(e) => setData('key', e.target.value)}
                                placeholder="STRIPE_SECRET"
                            />
                            <InputError message={errors.key} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="env-value">Value</Label>
                            <Input
                                id="env-value"
                                className="font-mono"
                                type={data.is_secret ? 'password' : 'text'}
                                autoComplete="off"
                                value={data.value}
                                onChange={(e) => setData('value', e.target.value)}
                                placeholder={existing?.is_secret ? 'Leave empty to keep the saved secret' : ''}
                            />
                            <InputError message={errors.value} />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-6">
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox checked={data.is_secret} onCheckedChange={(checked) => setData('is_secret', checked === true)} />
                            Secret. Nobody can read it after you save it.
                        </Label>
                        <div className="flex items-center gap-2">
                            <Label htmlFor="env-available">Available at</Label>
                            <Select value={data.available_at} onValueChange={(value) => setData('available_at', value as Availability)}>
                                <SelectTrigger id="env-available" className="w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(Object.keys(availabilityLabels) as Availability[]).map((value) => (
                                        <SelectItem key={value} value={value}>
                                            {availabilityLabels[value]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <Button disabled={processing || data.key.trim() === ''}>Save value</Button>
                </form>
            ) : (
                <p className="text-muted-foreground text-sm">Only an admin or an owner can change the values of a protected environment.</p>
            )}
        </div>
    );
}
