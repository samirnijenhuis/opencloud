import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Link, router, useForm } from '@inertiajs/react';
import { ChevronDown, Copy } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Change = 'replace' | 'remove';

const confirmations: Record<Change, { title: string; description: string; button: string }> = {
    replace: {
        title: 'Make a new deploy key?',
        description:
            'Opencloud uses the new key at once, and GitHub refuses it until you add it. Add the new key on GitHub, and delete the old key there.',
        button: 'Make a new key',
    },
    remove: {
        title: 'Remove the deploy key?',
        description:
            'Opencloud then reads the repository without a key. A private repository then needs the GitHub App. Delete the key on GitHub too.',
        button: 'Remove',
    },
};

export function DeployKey({
    applicationId,
    publicKey,
    settingsUrl,
    canUpdate,
    defaultOpen,
}: {
    applicationId: number;
    publicKey: string | null;
    settingsUrl: string;
    canUpdate: boolean;
    defaultOpen: boolean;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const [copied, setCopied] = useState(false);
    const [confirming, setConfirming] = useState<Change | null>(null);
    const [busy, setBusy] = useState(false);
    const [pasting, setPasting] = useState(false);
    const ownKey = useForm({ deploy_private_key: '' });

    const closeOwnKey = () => {
        ownKey.reset();
        ownKey.clearErrors();
        setPasting(false);
    };

    const saveOwnKey: FormEventHandler = (e) => {
        e.preventDefault();
        ownKey.post(route('applications.deploy-key.store', applicationId), { preserveScroll: true, onSuccess: closeOwnKey });
    };

    const ownKeyButton = !pasting && (
        <Button variant="secondary" size="sm" disabled={busy} onClick={() => setPasting(true)}>
            Use my own key
        </Button>
    );

    const send = (change: 'add' | Change) =>
        router.visit(route(change === 'remove' ? 'applications.deploy-key.destroy' : 'applications.deploy-key.store', applicationId), {
            method: change === 'remove' ? 'delete' : 'post',
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setConfirming(null);
            },
        });

    const copy = async (text: string) => {
        await navigator.clipboard.writeText(text);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="rounded-lg border">
            <div className="flex items-center justify-between gap-3 p-4">
                <div className="min-w-0">
                    <p className="text-sm font-medium">Deploy key</p>
                    <p className="text-muted-foreground text-sm">
                        {publicKey === null
                            ? 'None. A public repository or the GitHub App needs no key.'
                            : 'Set. Opencloud reads the repository with it.'}
                    </p>
                </div>
                <CollapsibleTrigger asChild>
                    <Button variant="ghost" size="sm" className="group">
                        {open ? 'Close' : 'Open'}
                        <ChevronDown className="transition-transform group-data-[state=open]:rotate-180" />
                    </Button>
                </CollapsibleTrigger>
            </div>

            <CollapsibleContent className="space-y-3 border-t p-4">
                <p className="text-muted-foreground text-sm">
                    A deploy key lets opencloud read a private repository on GitHub. The key can only read.
                </p>
                {publicKey === null && canUpdate && (
                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" disabled={busy} onClick={() => send('add')}>
                            Add deploy key
                        </Button>
                        {ownKeyButton}
                    </div>
                )}

                {publicKey !== null && (
                    <>
                        <div className="flex items-center gap-1 rounded-md border">
                            <code className="min-w-0 flex-1 truncate px-3 py-2 font-mono text-xs" title={publicKey}>
                                {publicKey}
                            </code>
                            <Button variant="ghost" size="icon" aria-label="Copy the public key" onClick={() => copy(publicKey)}>
                                <Copy />
                            </Button>
                        </div>
                        {copied && <p className="text-muted-foreground text-xs">Copied.</p>}

                        <ol className="list-decimal space-y-1 pl-5 text-sm">
                            <li>Open the repository on GitHub.</li>
                            <li>Go to Settings, then Deploy keys, and select Add deploy key.</li>
                            <li>Paste the key.</li>
                            <li>Keep Allow write access off.</li>
                            <li>Deploy an environment from its page.</li>
                        </ol>

                        <p className="text-muted-foreground text-sm">
                            A push can deploy through the deploy hook of an environment. Turn on the deploy hook in the{' '}
                            <Link href={settingsUrl} className="text-foreground font-medium hover:underline">
                                environment settings
                            </Link>
                            . Then add its URL on GitHub as a webhook, under Settings, Webhooks.
                        </p>

                        {canUpdate && (
                            <div className="flex flex-wrap gap-2">
                                <Button variant="secondary" size="sm" disabled={busy} onClick={() => setConfirming('replace')}>
                                    New key
                                </Button>
                                {ownKeyButton}
                                <Button variant="ghost" size="sm" disabled={busy} onClick={() => setConfirming('remove')}>
                                    Remove
                                </Button>
                            </div>
                        )}
                    </>
                )}

                {canUpdate && pasting && (
                    <form onSubmit={saveOwnKey} className="space-y-2">
                        <Textarea
                            aria-label="Private key"
                            className="font-mono text-xs"
                            rows={6}
                            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                            autoComplete="off"
                            spellCheck={false}
                            value={ownKey.data.deploy_private_key}
                            onChange={(e) => ownKey.setData('deploy_private_key', e.target.value)}
                        />
                        <p className="text-muted-foreground text-sm">
                            Paste the private key file, for example ~/.ssh/id_ed25519. The key must not have a passphrase.
                            {publicKey !== null && ' It replaces the current key at once.'} The page never shows the key again.
                        </p>
                        <InputError message={ownKey.errors.deploy_private_key} />
                        <div className="flex gap-2">
                            <Button size="sm" disabled={ownKey.processing || ownKey.data.deploy_private_key.trim() === ''}>
                                Save
                            </Button>
                            <Button type="button" variant="ghost" size="sm" onClick={closeOwnKey}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}
            </CollapsibleContent>

            <Dialog open={confirming !== null} onOpenChange={(open) => !open && setConfirming(null)}>
                {confirming && (
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{confirmations[confirming].title}</DialogTitle>
                            <DialogDescription>{confirmations[confirming].description}</DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="secondary" onClick={() => setConfirming(null)}>
                                Cancel
                            </Button>
                            <Button variant="destructive" disabled={busy} onClick={() => send(confirming)}>
                                {confirmations[confirming].button}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
        </Collapsible>
    );
}
