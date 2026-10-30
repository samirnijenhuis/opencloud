import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/** The delete also removes the data of each resource, so the user types the name of the environment first. */
export function DeleteEnvironment({ environment }: { environment: { id: number; name: string } }) {
    const form = useForm({ confirm: '' });
    // The action refuses the delete on `environment`, and Cloudflare refuses a record delete on `domain`.
    const errors = form.errors as Record<string, string>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.delete(route('environments.destroy', environment.id), { preserveScroll: true });
    };

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-200 p-4 dark:border-red-900">
            <p className="text-muted-foreground text-sm">
                Opencloud deletes the namespace, the domains, and the data of each database, bucket, and Valkey of this environment.
            </p>
            <Dialog
                onOpenChange={() => {
                    form.reset();
                    form.clearErrors();
                }}
            >
                <DialogTrigger asChild>
                    <Button variant="destructive" size="sm">
                        Delete environment
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-6">
                        <DialogHeader>
                            <DialogTitle>Delete {environment.name}?</DialogTitle>
                            <DialogDescription>
                                Opencloud deletes the namespace, the domains, and the data of each database, bucket, and Valkey of {environment.name}.
                                You cannot undo this. Production keeps its data.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="confirm">Type {environment.name} to confirm</Label>
                            <Input
                                id="confirm"
                                autoComplete="off"
                                value={form.data.confirm}
                                onChange={(e) => form.setData('confirm', e.target.value)}
                            />
                            <InputError message={errors.confirm ?? errors.environment ?? errors.domain} />
                        </div>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button variant="destructive" disabled={form.data.confirm !== environment.name || form.processing}>
                                Delete environment
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
