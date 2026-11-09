import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { previewWildcard } from '@/lib/format';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';
import { EditorSheet } from './editor-sheet';
import { previewSentence, useRepositoryAccess } from './repository-sheet';
import { type PreviewTemplateSettings, type ToolValue } from './types';

export function PreviewsSheet({
    open,
    onClose,
    applicationId,
    previews,
    canManage,
}: {
    open: boolean;
    onClose: () => void;
    applicationId: number;
    previews: PreviewTemplateSettings;
    canManage: boolean;
}) {
    const saved = {
        preview_domain: previews.preview_domain ?? '',
        preview_labels: previews.preview_labels.join(', '),
        max_previews: previews.max_previews,
        preview_tools: previews.preview_tools,
    };
    const form = useForm(saved);
    const { data, setData, errors } = form;
    // The list keeps the order of the tools, so a choice that the user takes back leaves the form clean.
    const toggleTool = (value: ToolValue, on: boolean) =>
        setData(
            'preview_tools',
            previews.tools.map((tool) => tool.value).filter((each) => (each === value ? on : data.preview_tools.includes(each))),
        );
    const wildcard = previewWildcard(data.preview_domain);
    const { access } = useRepositoryAccess();

    useEffect(() => {
        if (open) {
            form.setDefaults(saved);
            form.setData(saved);
            form.clearErrors();
        }
        // The form starts again from the saved values each time the sheet opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('applications.previews.update', applicationId), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <EditorSheet
            open={open}
            onClose={onClose}
            title="Previews"
            description="Each pull request gets its own environment with an empty database. It copies the settings of this template."
            footer={
                <>
                    <span className="text-muted-foreground text-xs">Production values never go to a preview.</span>
                    {canManage && (
                        <Button form="previews-settings" disabled={!form.isDirty || form.processing}>
                            {form.recentlySuccessful ? 'Saved' : 'Save'}
                        </Button>
                    )}
                </>
            }
        >
            {access?.previews === 'not_connected' && (
                <Alert>
                    <AlertDescription>{previewSentence(access)}</AlertDescription>
                </Alert>
            )}

            {!canManage && <p className="text-muted-foreground text-sm">Only an admin or an owner can change the previews.</p>}

            <form id="previews-settings" onSubmit={save}>
                <fieldset disabled={!canManage} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="preview_domain">Preview address</Label>
                        <Input
                            id="preview_domain"
                            className="font-mono"
                            value={data.preview_domain}
                            onChange={(e) => setData('preview_domain', e.target.value)}
                            placeholder="pr-{pr_number}.previews.example.com"
                        />
                        <p className="text-muted-foreground text-sm">
                            {wildcard && `Add one DNS record ${wildcard} that points to ${previews.ingress_ip ?? 'the cluster'}. `}
                            An empty field gives each preview a free sslip.io address.
                        </p>
                        <InputError message={errors.preview_domain} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="preview_labels">Only pull requests with these labels</Label>
                        <Input
                            id="preview_labels"
                            value={data.preview_labels}
                            onChange={(e) => setData('preview_labels', e.target.value)}
                            placeholder="preview"
                        />
                        <p className="text-muted-foreground text-sm">
                            Separate the labels with commas. A pull request gets a preview when it has one of the labels, and loses the preview when
                            it loses the last one. Leave it empty to give each pull request a preview.
                        </p>
                        <InputError message={errors.preview_labels} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="max_previews">Most previews at the same time</Label>
                        <Input
                            id="max_previews"
                            type="number"
                            min={0}
                            max={1000}
                            className="w-24"
                            value={data.max_previews}
                            onChange={(e) => setData('max_previews', Number(e.target.value))}
                        />
                        <p className="text-muted-foreground text-sm">0 means no limit.</p>
                        <InputError message={errors.max_previews} />
                    </div>

                    <div className="grid gap-2">
                        <span className="text-sm leading-none font-medium">Tools of each preview</span>
                        {previews.tools.map((tool) => (
                            <Label key={tool.value} className="flex min-w-0 items-center gap-2 font-normal">
                                <Checkbox
                                    checked={data.preview_tools.includes(tool.value)}
                                    onCheckedChange={(checked) => toggleTool(tool.value, checked === true)}
                                />
                                {tool.label}
                                {tool.address && (
                                    <code className="text-muted-foreground ml-auto truncate font-mono text-xs" title={tool.address}>
                                        {tool.address}
                                    </code>
                                )}
                            </Label>
                        ))}
                        <p className="text-muted-foreground text-sm">
                            Each new preview gets these tools behind a password. phpMyAdmin opens the default database when it is MySQL. A MAIL_ value
                            of this template still wins over Mailpit.
                        </p>
                        <InputError message={errors.preview_tools} />
                    </div>
                </fieldset>
            </form>
        </EditorSheet>
    );
}
