import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Choice = { value: string; label: string };

type Props = {
    regions: Choice[];
    node_sizes: Choice[];
    max_nodes: number;
    pricing_url: string;
    has_key: boolean;
};

function ChoiceSelect({ id, value, choices, onChange }: { id: string; value: string; choices: Choice[]; onChange: (value: string) => void }) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger id={id}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {choices.map((choice) => (
                    <SelectItem key={choice.value} value={choice.value}>
                        {choice.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function ClustersCreateDigitalOcean({ regions, node_sizes, max_nodes, pricing_url, has_key }: Props) {
    const form = useForm({
        name: '',
        region: regions[0]?.value ?? '',
        node_size: node_sizes[1]?.value ?? node_sizes[0]?.value ?? '',
        min_nodes: 1,
        max_nodes: 3,
    });
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('clusters.digitalocean.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clusters', href: '/clusters' },
                { title: 'Create on DigitalOcean', href: '/clusters/create/digitalocean' },
            ]}
        >
            <Head title="Create a cluster on DigitalOcean" />

            <form onSubmit={submit} className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Create a cluster on DigitalOcean</h1>
                    <p className="text-muted-foreground text-sm">
                        OpenTofu creates a Kubernetes cluster and a container registry with your DigitalOcean key. Then opencloud installs its parts,
                        like on a connected cluster. This takes about ten minutes.
                    </p>
                </div>

                {!has_key && (
                    <Alert>
                        <AlertDescription>
                            <span>
                                Add a DigitalOcean key in{' '}
                                <Link href={route('provider-keys.index')} className="underline">
                                    Settings, Provider keys
                                </Link>{' '}
                                first.
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        className="max-w-xs"
                        placeholder="ams3-production"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <p className="text-muted-foreground text-sm">
                        Lowercase letters, digits, and dashes. DigitalOcean shows the cluster with this name.
                    </p>
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="region">Region</Label>
                        <ChoiceSelect id="region" value={data.region} choices={regions} onChange={(value) => setData('region', value)} />
                        <InputError message={errors.region} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="node_size">Node size</Label>
                        <ChoiceSelect id="node_size" value={data.node_size} choices={node_sizes} onChange={(value) => setData('node_size', value)} />
                        <InputError message={errors.node_size} />
                    </div>
                </div>

                <div className="space-y-2">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="min_nodes">Fewest nodes</Label>
                            <Input
                                id="min_nodes"
                                type="number"
                                min={1}
                                max={max_nodes}
                                value={data.min_nodes}
                                onChange={(e) => setData('min_nodes', Number(e.target.value))}
                            />
                            <InputError message={errors.min_nodes} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="max_nodes">Most nodes</Label>
                            <Input
                                id="max_nodes"
                                type="number"
                                min={1}
                                max={max_nodes}
                                value={data.max_nodes}
                                onChange={(e) => setData('max_nodes', Number(e.target.value))}
                            />
                            <InputError message={errors.max_nodes} />
                        </div>
                    </div>
                    <p className="text-muted-foreground text-sm">DigitalOcean adds nodes when the pods need more room and removes idle nodes.</p>
                </div>

                <p className="rounded-lg border p-4 text-sm">
                    DigitalOcean charges for each node, for the load balancer of Caddy, and for the registry. Read the{' '}
                    <a href={pricing_url} target="_blank" rel="noreferrer" className="underline">
                        Kubernetes prices on DigitalOcean
                    </a>
                    .
                </p>

                <Button disabled={processing || !has_key}>{processing ? 'Starting' : 'Create cluster'}</Button>
            </form>
        </AppLayout>
    );
}
