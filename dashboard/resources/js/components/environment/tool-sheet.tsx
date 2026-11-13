import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import { Choices } from './compute-fields';
import { EditorSheet } from './editor-sheet';
import { CopyLine } from './resource-sheet';
import { type Tool, type ToolKind, type ToolKindOption, type ToolLogin } from './types';

/** The kind is null only while no kind is left to add, and then the sheet does not open in add mode. */
type ToolForm = { kind: ToolKind | null; hostname: string };

const DNS_HINT = 'Point a DNS record for this address at the cluster. The wildcard record of the previews covers an address in the same domain.';

const kindHelp: Record<ToolKind, string> = {
    mailpit: 'The app gets MAIL_HOST and the other mail values. A MAIL_ value of the environment still wins.',
    phpmyadmin: 'phpMyAdmin opens the default database when it is MySQL.',
};

function initialValues(tool: Tool | undefined, kinds: ToolKindOption[]): ToolForm {
    if (tool) {
        return { kind: tool.kind, hostname: tool.hostname };
    }

    return { kind: kinds[0]?.value ?? null, hostname: kinds[0]?.hostname ?? '' };
}

/** Adds a tool, or changes the address of a tool and removes it. On the preview template, the address is a pattern for each new preview. */
export function ToolSheet({
    open,
    onClose,
    environmentId,
    template,
    tool,
    kinds,
    login,
    canUpdate,
}: {
    open: boolean;
    onClose: () => void;
    environmentId: number;
    template: boolean;
    tool: Tool | undefined;
    kinds: ToolKindOption[];
    login: ToolLogin | null;
    canUpdate: boolean;
}) {
    const form = useForm<ToolForm>(initialValues(tool, kinds));
    const { data, setData, errors } = form;
    const [removing, setRemoving] = useState(false);
    const locked = !canUpdate;

    useEffect(() => {
        if (open) {
            const values = initialValues(tool, kinds);
            form.setDefaults(values);
            form.setData(values);
            form.clearErrors();
        }
        // The form starts again from the saved values each time the sheet opens or shows another tool.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, tool?.id]);

    const pick = (kind: ToolKind) => setData({ kind, hostname: kinds.find((option) => option.value === kind)?.hostname ?? '' });

    const save: FormEventHandler = (e) => {
        e.preventDefault();

        if (tool) {
            form.patch(route('tools.update', tool.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
        } else {
            form.post(route('tools.store', environmentId), { preserveScroll: true, onSuccess: onClose });
        }
    };

    const remove = () => {
        if (tool) {
            router.delete(route('tools.destroy', tool.id), {
                preserveScroll: true,
                onSuccess: () => {
                    setRemoving(false);
                    onClose();
                },
            });
        }
    };

    const removal = template
        ? 'Each new preview stops getting this tool. The previews that run keep it.'
        : 'Caddy stops the route at once. The next deploy stops its pod.';

    return (
        <>
            <EditorSheet
                open={open}
                onClose={onClose}
                title={tool ? tool.label : 'New tool'}
                description={
                    template
                        ? 'Each new preview gets this tool next to its app, with the number of its pull request in the address.'
                        : 'This tool runs next to the app of this preview, on an address of its own.'
                }
                footer={
                    <>
                        <span className="text-muted-foreground text-xs">
                            {template
                                ? 'The previews that run keep their tools.'
                                : tool
                                  ? 'Caddy routes a new address at once.'
                                  : 'A new tool starts on the next deploy.'}
                        </span>
                        {canUpdate && (
                            <Button form="tool-settings" disabled={form.processing || (tool !== undefined && !form.isDirty)}>
                                {tool ? (form.recentlySuccessful ? 'Saved' : 'Save') : 'Add tool'}
                            </Button>
                        )}
                    </>
                }
            >
                <form id="tool-settings" onSubmit={save}>
                    <fieldset disabled={locked} className="space-y-6">
                        {!tool && (
                            <div className="grid gap-2">
                                <Label>Tool</Label>
                                {data.kind && (
                                    <Choices
                                        options={kinds.map((option) => ({ value: option.value, label: option.label, text: option.description }))}
                                        value={data.kind}
                                        onChange={pick}
                                    />
                                )}
                                <InputError message={errors.kind} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="tool-hostname">Address</Label>
                            <div className="flex">
                                <span className="bg-muted text-muted-foreground inline-flex items-center rounded-l-md border border-r-0 px-3 font-mono text-sm">
                                    https://
                                </span>
                                <Input
                                    id="tool-hostname"
                                    className="rounded-l-none font-mono"
                                    placeholder={template ? 'mail-{pr_number}.previews.example.com' : 'mail-12.previews.example.com'}
                                    value={data.hostname}
                                    onChange={(e) => setData('hostname', e.target.value)}
                                />
                            </div>
                            <p className="text-muted-foreground text-sm">
                                {template && 'Keep {pr_number} in the first part of the address. Each new preview puts its number there. '}
                                {DNS_HINT}
                            </p>
                            <InputError message={errors.hostname} />
                        </div>

                        {data.kind && <p className="text-muted-foreground text-sm">{kindHelp[data.kind]}</p>}
                    </fieldset>
                </form>

                {login && (
                    <section className="space-y-3 border-t pt-5">
                        <div>
                            <h3 className="text-sm font-medium">Login</h3>
                            <p className="text-muted-foreground text-sm">
                                Each tool of this preview asks for this login. The password ends with the preview.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label>User name</Label>
                            <CopyLine text={login.username} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Password</Label>
                            <CopyLine text={login.password} />
                        </div>
                    </section>
                )}

                {tool && canUpdate && (
                    <section className="space-y-2 border-t pt-5">
                        <h3 className="text-sm font-medium">Remove</h3>
                        <p className="text-muted-foreground text-sm">{removal}</p>
                        <Button variant="destructive" size="sm" onClick={() => setRemoving(true)}>
                            Remove {tool.label}
                        </Button>
                    </section>
                )}
            </EditorSheet>

            <Dialog open={removing} onOpenChange={setRemoving}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove {tool?.label}?</DialogTitle>
                        <DialogDescription>{removal}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setRemoving(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={remove}>
                            Remove
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
