import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/** The delete removes each environment and the data of its resources, so the user types the name of the application first. */
export function ApplicationSettings({ application, canDelete }: { application: { id: number; name: string }; canDelete: boolean }) {
    const [open, setOpen] = useState(false);
    const rename = useForm({ name: application.name });
    const remove = useForm({ confirm: '' });
    // DeleteEnvironment refuses the delete on `environment`, and Cloudflare refuses a record delete on `domain`.
    const removeErrors = remove.errors as Record<string, string>;

    const change = (next: boolean) => {
        setOpen(next);

        if (!next) {
            rename.reset();
            rename.clearErrors();
            remove.reset();
            remove.clearErrors();
        }
    };

    const submitName: FormEventHandler = (e) => {
        e.preventDefault();
        rename.patch(route('applications.update', application.id), {
            preserveScroll: true,
            onSuccess: () => {
                rename.setDefaults();
                setOpen(false);
            },
        });
    };

    const submitDelete: FormEventHandler = (e) => {
        e.preventDefault();
        remove.delete(route('applications.destroy', application.id));
    };

    return (
        <Dialog open={open} onOpenChange={change}>
            <DialogTrigger asChild>
                <Button variant="secondary" size="sm">
                    Settings
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Settings</DialogTitle>
                    <DialogDescription>Rename or delete {application.name}.</DialogDescription>
                </DialogHeader>

                <form onSubmit={submitName} className="grid gap-2">
                    <Label htmlFor="application_name">Name</Label>
                    <div className="flex gap-2">
                        <Input
                            id="application_name"
                            autoComplete="off"
                            value={rename.data.name}
                            onChange={(e) => rename.setData('name', e.target.value)}
                        />
                        <Button disabled={!rename.isDirty || rename.processing}>Save</Button>
                    </div>
                    <InputError message={rename.errors.name} />
                    <p className="text-muted-foreground text-xs">
                        The domains keep their hostnames. New previews and new free domains use the new name.
                    </p>
                </form>

                {canDelete && (
                    <form onSubmit={submitDelete} className="grid gap-3 rounded-lg border border-red-200 p-4 dark:border-red-900">
                        <div className="space-y-1">
                            <h3 className="text-sm font-medium">Delete application</h3>
                            <p className="text-muted-foreground text-sm">
                                Opencloud deletes each environment of {application.name} with its namespace, its domains, and the data of each
                                database, bucket, and Valkey. You cannot undo this.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="confirm_application">Type {application.name} to confirm</Label>
                            <Input
                                id="confirm_application"
                                autoComplete="off"
                                value={remove.data.confirm}
                                onChange={(e) => remove.setData('confirm', e.target.value)}
                            />
                            <InputError message={removeErrors.confirm ?? removeErrors.environment ?? removeErrors.domain} />
                        </div>
                        <div className="flex justify-end">
                            <Button variant="destructive" size="sm" disabled={remove.data.confirm !== application.name || remove.processing}>
                                Delete application
                            </Button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
