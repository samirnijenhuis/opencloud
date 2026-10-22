import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ClustersCreate() {
    const form = useForm<{
        name: string;
        kubeconfig: string;
        registry_url: string;
        registry_username: string;
        registry_password: string;
        registry_insecure: boolean;
    }>({
        name: '',
        kubeconfig: '',
        registry_url: '',
        registry_username: '',
        registry_password: '',
        registry_insecure: false,
    });
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('clusters.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clusters', href: '/clusters' },
                { title: 'Connect', href: '/clusters/create' },
            ]}
        >
            <Head title="Connect a cluster" />

            <form onSubmit={submit} className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Connect a cluster</h1>
                    <p className="text-muted-foreground text-sm">
                        Opencloud installs Caddy and its other parts in its own namespaces. It does not change other software on the cluster.
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input id="name" className="max-w-xs" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="kubeconfig">Kubeconfig</Label>
                    <Textarea
                        id="kubeconfig"
                        rows={10}
                        className="font-mono text-xs"
                        placeholder="apiVersion: v1&#10;kind: Config&#10;..."
                        value={data.kubeconfig}
                        onChange={(e) => setData('kubeconfig', e.target.value)}
                    />
                    <p className="text-muted-foreground text-sm">
                        Use a kubeconfig with the cluster-admin role. It must not need a cloud CLI to log in. Opencloud stores it encrypted.
                    </p>
                    <InputError message={errors.kubeconfig} />
                </div>

                <fieldset className="space-y-4 rounded-lg border p-4">
                    <legend className="px-1 text-sm font-medium">Registry</legend>
                    <p className="text-muted-foreground text-sm">
                        Opencloud copies each image to this registry. The cluster pulls from it, so new pods start while the dashboard server is down.
                    </p>

                    <div className="grid gap-2">
                        <Label htmlFor="registry_url">Registry address</Label>
                        <Input
                            id="registry_url"
                            placeholder="registry.example.com/team"
                            value={data.registry_url}
                            onChange={(e) => setData('registry_url', e.target.value)}
                        />
                        <InputError message={errors.registry_url} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="registry_username">Username</Label>
                            <Input
                                id="registry_username"
                                autoComplete="off"
                                value={data.registry_username}
                                onChange={(e) => setData('registry_username', e.target.value)}
                            />
                            <InputError message={errors.registry_username} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="registry_password">Password or token</Label>
                            <Input
                                id="registry_password"
                                type="password"
                                autoComplete="new-password"
                                value={data.registry_password}
                                onChange={(e) => setData('registry_password', e.target.value)}
                            />
                            <InputError message={errors.registry_password} />
                        </div>
                    </div>

                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox checked={data.registry_insecure} onCheckedChange={(checked) => setData('registry_insecure', checked === true)} />
                        Plain HTTP without a TLS check. Use this only for a test registry.
                    </Label>
                </fieldset>

                <Button disabled={processing}>{processing ? 'Checking the cluster' : 'Connect cluster'}</Button>
            </form>
        </AppLayout>
    );
}
