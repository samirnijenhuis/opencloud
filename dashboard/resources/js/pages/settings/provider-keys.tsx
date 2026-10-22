import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type CheckResult = {
    provider: string;
    status: 'ok' | 'invalid' | 'missing_rights';
    saved: boolean;
    message: string;
    missing: string[];
};

type ProviderGuide = {
    key: string;
    label: string;
    use: string;
    url: string | null;
    steps: string[];
    rights: { label: string; optional: boolean }[];
    command: string | null;
    paste_hint: string;
    stored: { id: number; checked_at: string | null; missing_rights: string[] } | null;
    result: CheckResult | null;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Provider keys', href: '/settings/providers' }];

function CopyButton({ text, label }: { text: string; label: string }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard.writeText(text).then(() => setCopied(true));
    };

    return (
        <Button type="button" variant="secondary" size="sm" onClick={copy}>
            {copied ? 'Copied' : label}
        </Button>
    );
}

function Result({ result }: { result: CheckResult }) {
    const tone =
        result.status === 'ok'
            ? 'text-green-700 dark:text-green-400'
            : result.status === 'invalid'
              ? 'text-red-700 dark:text-red-400'
              : 'text-amber-700 dark:text-amber-400';
    const title =
        result.status === 'ok'
            ? 'The key works and is saved.'
            : result.status === 'invalid'
              ? 'The key is not saved.'
              : result.saved
                ? 'The key is saved. An optional right is missing.'
                : 'The key is not saved. A right is missing.';

    return (
        <div className={`space-y-1 text-sm ${tone}`} role="status">
            <p className="font-medium">{title}</p>
            <p>{result.message}</p>
            {result.missing.length > 0 && (
                <ul className="list-disc pl-5">
                    {result.missing.map((right) => (
                        <li key={right}>Missing: {right}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function ProviderSection({ guide }: { guide: ProviderGuide }) {
    const form = useForm({ token: '' });
    const { stored } = guide;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('provider-keys.update', guide.key), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const remove = () => {
        if (stored && confirm(`Remove the ${guide.label} key? opencloud stops using ${guide.label}.`)) {
            router.delete(route('provider-keys.destroy', stored.id), { preserveScroll: true });
        }
    };

    return (
        <section className="space-y-4 rounded-lg border p-4">
            <div>
                <h3 className="font-medium">{guide.label}</h3>
                <p className="text-muted-foreground text-sm">{guide.use}</p>
                <p className="mt-1 text-sm" data-testid={`state-${guide.key}`}>
                    {stored ? `Set, checked ${timeAgo(stored.checked_at).toLowerCase()}` : 'Not set'}
                </p>
                {stored && stored.missing_rights.length > 0 && (
                    <p className="text-sm text-amber-700 dark:text-amber-400">Missing optional rights: {stored.missing_rights.join(', ')}</p>
                )}
            </div>

            <ol className="list-decimal space-y-1 pl-5 text-sm">
                {guide.steps.map((step) => (
                    <li key={step}>{step}</li>
                ))}
            </ol>

            {guide.url && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button asChild size="sm">
                        <a href={guide.url} target="_blank" rel="noreferrer">
                            Open the {guide.label} page
                        </a>
                    </Button>
                    <CopyButton text={guide.url} label="Copy link" />
                </div>
            )}

            {guide.command && (
                <div className="space-y-2">
                    <div className="flex items-center justify-between gap-2">
                        <Label>Command</Label>
                        <CopyButton text={guide.command} label="Copy command" />
                    </div>
                    <pre className="bg-muted overflow-x-auto rounded-md p-3 text-xs break-all whitespace-pre-wrap">{guide.command}</pre>
                </div>
            )}

            <div className="space-y-1">
                <p className="text-sm font-medium">Rights to select</p>
                <ul className="list-disc pl-5 text-sm">
                    {guide.rights.map((right) => (
                        <li key={right.label}>
                            {right.label}
                            {right.optional && <span className="text-muted-foreground"> (optional)</span>}
                        </li>
                    ))}
                </ul>
            </div>

            <form onSubmit={submit} className="space-y-2">
                <Label htmlFor={`token-${guide.key}`}>Paste the key</Label>
                <div className="flex gap-2">
                    <Input
                        id={`token-${guide.key}`}
                        type="password"
                        autoComplete="off"
                        placeholder={guide.paste_hint}
                        value={form.data.token}
                        onChange={(e) => form.setData('token', e.target.value)}
                    />
                    <Button disabled={form.processing || form.data.token.trim() === ''}>Check and save</Button>
                </div>
                <InputError message={form.errors.token} />
            </form>

            {guide.result && <Result result={guide.result} />}

            {stored && (
                <div className="flex gap-2">
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => router.post(route('provider-keys.check', stored.id), {}, { preserveScroll: true })}
                    >
                        Check again
                    </Button>
                    <Button variant="ghost" size="sm" onClick={remove}>
                        Remove key
                    </Button>
                </div>
            )}
        </section>
    );
}

export default function ProviderKeys({ providers }: { providers: ProviderGuide[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Provider keys" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Provider keys"
                        description="Each key gives opencloud the smallest set of rights it needs. opencloud checks a key when you paste it."
                    />

                    {providers.map((guide) => (
                        <ProviderSection key={guide.key} guide={guide} />
                    ))}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
