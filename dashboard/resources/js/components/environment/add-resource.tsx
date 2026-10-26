import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';
import { type ConnectionField, type Datastores, type Offer } from './types';

type Values = Record<string, string | boolean>;

const inputTypes = { text: 'text', number: 'number', secret: 'password' } as const;

/** A database asks for its name, a paid or outside resource explains itself, and an address needs its fields. */
export const needsDialog = (offer: Offer) => offer.named || offer.note !== null || offer.fields.length > 0;

function startValues(fields: ConnectionField[]): Values {
    return Object.fromEntries(fields.map((field) => [field.key, typeof field.value === 'boolean' ? field.value : String(field.value ?? '')]));
}

function AddressField({
    field,
    value,
    error,
    onChange,
}: {
    field: ConnectionField;
    value: string | boolean;
    error: string | undefined;
    onChange: (value: string | boolean) => void;
}) {
    const id = `resource-${field.key}`;
    const help = field.help && <p className="text-muted-foreground text-xs">{field.help}</p>;

    if (field.type === 'toggle') {
        return (
            <div className="grid gap-1.5">
                <div className="flex items-center gap-2">
                    <Checkbox id={id} checked={value === true} onCheckedChange={(checked) => onChange(checked === true)} />
                    <Label htmlFor={id}>{field.label}</Label>
                </div>
                {help}
                <InputError message={error} />
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>
                {field.label}
                {!field.required && <span className="text-muted-foreground font-normal"> (optional)</span>}
            </Label>
            <Input
                id={id}
                type={inputTypes[field.type]}
                value={String(value)}
                onChange={(e) => onChange(e.target.value)}
                placeholder={field.placeholder ?? undefined}
                autoComplete={field.type === 'secret' ? 'new-password' : 'off'}
                spellCheck={false}
                className={field.type === 'secret' ? undefined : 'font-mono'}
            />
            {help}
            <InputError message={error} />
        </div>
    );
}

/** The dialog content mounts on each open, so the form starts fresh: "main" for the first database, empty after. */
export function AddResourceForm({
    environmentId,
    datastores,
    offer,
    onClose,
}: {
    environmentId: number;
    datastores: Datastores;
    offer: Offer;
    onClose: () => void;
}) {
    const first = offer.named && !datastores.attachments.some((attachment) => attachment.name !== null);
    const [name, setName] = useState(first ? 'main' : '');
    const [values, setValues] = useState<Values>(() => startValues(offer.fields));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.post(
            route('datastores.store', environmentId),
            { kind: offer.kind, source: offer.source, name: offer.named ? name : null, ...values },
            {
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    setErrors({});
                },
                onSuccess: onClose,
                // The page reloads its datastores while one is pending, and a reload clears the page errors.
                onError: setErrors,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <form onSubmit={submit} className="grid min-w-0 gap-4">
            <DialogHeader>
                <DialogTitle>Add {offer.label}</DialogTitle>
                <DialogDescription>
                    {!offer.named
                        ? `${offer.description}.`
                        : first
                          ? 'This is the first database of the environment, so it is the default.'
                          : 'The default database keeps its env values. The app reaches this database through one URL value.'}
                </DialogDescription>
            </DialogHeader>

            {offer.named && (
                <div className="grid gap-2">
                    <Label htmlFor="resource-name">Connection name</Label>
                    <Input
                        id="resource-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="reports"
                        autoComplete="off"
                        spellCheck={false}
                        autoFocus
                        className="font-mono"
                    />
                    <p className="text-muted-foreground text-xs">Use 1 to 30 lowercase letters, digits, and underscores. Start with a letter.</p>
                    <InputError message={errors.name ?? errors.kind} />
                </div>
            )}

            {offer.fields.map((field) => (
                <AddressField
                    key={field.key}
                    field={field}
                    value={values[field.key]}
                    error={errors[field.key]}
                    onChange={(value) => setValues((current) => ({ ...current, [field.key]: value }))}
                />
            ))}

            {offer.note && <p className="text-muted-foreground text-sm">{offer.note}</p>}
            {!offer.named && <InputError message={errors.kind} />}

            <DialogFooter className="gap-2">
                <Button type="button" variant="secondary" onClick={onClose}>
                    Cancel
                </Button>
                <Button disabled={busy || (offer.named && name.trim() === '')}>Add {offer.label}</Button>
            </DialogFooter>
        </form>
    );
}
