import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Props = { target: { endpoint: string; bucket: string; region: string; path_style: boolean; keys_set: boolean } | null };

export default function Backups({ target }: Props) {
    const form = useForm({
        endpoint: target?.endpoint ?? '',
        bucket: target?.bucket ?? '',
        region: target?.region ?? 'us-east-1',
        path_style: target?.path_style ?? true,
        access_key: '',
        secret_key: '',
    });
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('backup-target.update'), { preserveScroll: true, onSuccess: () => form.reset('access_key', 'secret_key') });
    };

    const remove = () => {
        if (confirm('Delete the backup target? New backups go to the disk of this server. Backups in the bucket stay there.')) {
            router.delete(route('backup-target.destroy'), {
                preserveScroll: true,
                onSuccess: () => form.setData({ endpoint: '', bucket: '', region: 'us-east-1', path_style: true, access_key: '', secret_key: '' }),
            });
        }
    };

    const keyPlaceholder = target ? 'Leave empty to keep the saved key' : '';

    return (
        <AppLayout breadcrumbs={[{ title: 'Backups', href: route('backup-target.edit') }]}>
            <Head title="Backups" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Backups"
                        description="Send database backups to an S3 bucket. Without a target they stay on this server's disk."
                    />

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="endpoint">Endpoint</Label>
                            <Input
                                id="endpoint"
                                value={data.endpoint}
                                onChange={(e) => setData('endpoint', e.target.value)}
                                placeholder="https://s3.example.com"
                            />
                            <InputError message={errors.endpoint} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="bucket">Bucket</Label>
                            <Input id="bucket" value={data.bucket} onChange={(e) => setData('bucket', e.target.value)} />
                            <InputError message={errors.bucket} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="region">Region</Label>
                            <Input id="region" value={data.region} onChange={(e) => setData('region', e.target.value)} />
                            <InputError message={errors.region} />
                        </div>
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox checked={data.path_style} onCheckedChange={(checked) => setData('path_style', checked === true)} />
                            Use path-style addresses. Most services other than AWS need this.
                        </Label>
                        <div className="grid gap-2">
                            <Label htmlFor="access_key" className="flex items-center gap-2">
                                Access key {target?.keys_set && <Badge variant="secondary">Set</Badge>}
                            </Label>
                            <Input
                                id="access_key"
                                autoComplete="off"
                                value={data.access_key}
                                onChange={(e) => setData('access_key', e.target.value)}
                                placeholder={keyPlaceholder}
                            />
                            <InputError message={errors.access_key} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="secret_key" className="flex items-center gap-2">
                                Secret key {target?.keys_set && <Badge variant="secondary">Set</Badge>}
                            </Label>
                            <Input
                                id="secret_key"
                                type="password"
                                autoComplete="off"
                                value={data.secret_key}
                                onChange={(e) => setData('secret_key', e.target.value)}
                                placeholder={keyPlaceholder}
                            />
                            <InputError message={errors.secret_key} />
                        </div>
                        <p className="text-muted-foreground text-sm">
                            The dashboard writes and deletes a small test file in the bucket before it saves.
                        </p>
                        <div className="flex items-center gap-2">
                            <Button disabled={processing}>{processing ? 'Checking' : 'Check and save'}</Button>
                            {target && (
                                <Button type="button" variant="ghost" onClick={remove}>
                                    Delete target
                                </Button>
                            )}
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
