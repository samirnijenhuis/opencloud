import { Chip } from '@/components/chip';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const sources = [
    {
        value: 'production',
        title: 'Copy production',
        description: 'The settings, the processes, and the plain env values of production. The secrets stay in production.',
    },
    {
        value: 'preview_template',
        title: 'Copy the preview template',
        description: 'The settings, the processes, and the env values that each new preview gets. Web runs one instance.',
    },
] as const;

/** A permanent environment for any branch. A push to the branch deploys it, the same as production. */
export function NewEnvironment({ applicationId }: { applicationId: number }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ name: string; branch: string; copy_from: (typeof sources)[number]['value'] }>({
        name: '',
        branch: '',
        copy_from: 'production',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('environments.store', applicationId));
    };

    const change = (next: boolean) => {
        setOpen(next);

        if (!next) {
            form.reset();
            form.clearErrors();
        }
    };

    return (
        <Dialog open={open} onOpenChange={change}>
            <DialogTrigger asChild>
                <Button variant="secondary" size="sm">
                    New environment
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-6">
                    <DialogHeader>
                        <DialogTitle>New environment</DialogTitle>
                        <DialogDescription>
                            The environment gets its own free domain and new, empty resources. It deploys when you select Deploy or push to its
                            branch.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="environment_name">Name</Label>
                        <Input
                            id="environment_name"
                            autoComplete="off"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="staging"
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="environment_branch">Branch</Label>
                        <Input
                            id="environment_branch"
                            className="font-mono"
                            autoComplete="off"
                            value={form.data.branch}
                            onChange={(e) => form.setData('branch', e.target.value)}
                            placeholder="staging"
                        />
                        <InputError message={form.errors.branch} />
                    </div>

                    <div className="grid gap-2">
                        <span className="text-sm font-medium">Start from</span>
                        <div className="grid gap-2">
                            {sources.map((source) => (
                                <Chip
                                    key={source.value}
                                    selected={form.data.copy_from === source.value}
                                    onClick={() => form.setData('copy_from', source.value)}
                                >
                                    <span className="font-medium">{source.title}</span>
                                    <span className="text-muted-foreground block text-xs">{source.description}</span>
                                </Chip>
                            ))}
                        </div>
                        <InputError message={form.errors.copy_from} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="secondary" onClick={() => change(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>Create environment</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
