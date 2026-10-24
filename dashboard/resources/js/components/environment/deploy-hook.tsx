import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePoll } from '@/hooks/use-poll';
import { router, usePage } from '@inertiajs/react';
import { Copy, Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';

type Hook = { enabled: boolean; url: string | null };

/** The URL holds the token, so the page hides the token until the user asks for it. */
const masked = (url: string) => url.replace(/[^/]+$/, '•'.repeat(16));

export function DeployHook({ environmentId, canUpdate }: { environmentId: number; canUpdate: boolean }) {
    const [hook, reload] = usePoll<Hook>(route('environments.deploy-hook', environmentId), 10_000, () => false);
    const errors = usePage().props.errors as Record<string, string>;
    const [revealed, setRevealed] = useState(false);
    const [copied, setCopied] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const send = (method: 'post' | 'delete', name: string) =>
        router.visit(route(name, environmentId), {
            method,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onSuccess: () => {
                setRevealed(false);
                reload();
            },
            onFinish: () => {
                setBusy(false);
                setConfirming(false);
            },
        });

    const copy = async (url: string) => {
        await navigator.clipboard.writeText(url);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <h3 className="text-sm font-medium">Deploy hook</h3>
                    <p className="text-muted-foreground text-sm">A POST request to this URL deploys the head of the branch. Use it in CI.</p>
                </div>
                {hook && canUpdate && (
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={busy}
                        onClick={() =>
                            send(
                                hook.enabled ? 'delete' : 'post',
                                hook.enabled ? 'environments.deploy-hook.disable' : 'environments.deploy-hook.enable',
                            )
                        }
                    >
                        {hook.enabled ? 'Turn off' : 'Turn on'}
                    </Button>
                )}
            </div>

            {hook === null && <p className="text-muted-foreground text-sm">Loading the deploy hook.</p>}
            {hook && !hook.enabled && <p className="text-sm">The deploy hook is off.</p>}
            {hook?.enabled && !canUpdate && (
                <p className="text-sm">The deploy hook is on. Only a member who can change this environment sees its URL.</p>
            )}

            {hook?.enabled && hook.url && (
                <>
                    <div className="flex items-center gap-1 rounded-md border">
                        <code className="min-w-0 flex-1 truncate px-3 py-2 font-mono text-xs" title={revealed ? hook.url : undefined}>
                            {revealed ? hook.url : masked(hook.url)}
                        </code>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={revealed ? 'Hide the URL' : 'Show the URL'}
                            onClick={() => setRevealed(!revealed)}
                        >
                            {revealed ? <EyeOff /> : <Eye />}
                        </Button>
                        <Button variant="ghost" size="icon" aria-label="Copy the URL" onClick={() => copy(hook.url!)}>
                            <Copy />
                        </Button>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-muted-foreground text-xs">
                            {copied ? 'Copied.' : 'Keep the URL secret. Anyone with it can deploy.'}
                        </span>
                        <Button variant="ghost" size="sm" disabled={busy} onClick={() => setConfirming(true)}>
                            Make a new URL
                        </Button>
                    </div>
                </>
            )}
            <InputError message={errors.deploy_hook} />

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Make a new deploy hook URL?</DialogTitle>
                        <DialogDescription>
                            The old URL stops working at once. Put the new URL in each CI job that uses the old one.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={busy} onClick={() => send('post', 'environments.deploy-hook.regenerate')}>
                            Make a new URL
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
