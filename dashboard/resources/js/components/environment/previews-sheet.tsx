import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { previewWildcard } from '@/lib/format';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';
import { EditorSheet } from './editor-sheet';
import { previewSentence, useRepositoryAccess } from './repository-sheet';
import { type PreviewTemplateSettings } from './types';

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
    const saved = { preview_domain: previews.preview_domain ?? '', preview_labels: previews.preview_labels.join(', ') };
    const form = useForm(saved);
    const { data, setData, errors } = form;
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
                </fieldset>
            </form>
        </EditorSheet>
    );
}
