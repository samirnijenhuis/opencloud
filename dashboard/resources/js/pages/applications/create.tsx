import { Chip } from '@/components/chip';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { FormEventHandler } from 'react';

type Cluster = { id: number; name: string; kind: string; ingress_ip: string | null };
type DeployKeyChoice = 'none' | 'new' | 'own';

type FormData = {
    repository: string;
    branch: string;
    deploy_key: DeployKeyChoice;
    deploy_private_key: string;
    cluster_id: string;
};

function Row({ label, hint, htmlFor, children }: { label: string; hint?: string; htmlFor?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-2 border-b py-5 sm:grid-cols-[11rem_1fr] sm:gap-6">
            <div>
                <Label htmlFor={htmlFor} className="text-sm font-medium">
                    {label}
                </Label>
                {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
            </div>
            <div className="min-w-0 space-y-2">{children}</div>
        </div>
    );
}

export default function ApplicationCreate({ clusters, defaultClusterId }: { clusters: Cluster[]; defaultClusterId: number | null }) {
    const form = useForm<FormData>({
        repository: '',
        branch: '',
        deploy_key: 'none',
        deploy_private_key: '',
        cluster_id: defaultClusterId ? String(defaultClusterId) : '',
    });
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((current) => ({
            ...current,
            // Only the Laravel preset is offered for now.
            type: 'laravel',
            port: null,
            deploy_key: current.deploy_key === 'new',
            deploy_private_key: current.deploy_key === 'own' ? current.deploy_private_key : null,
        }));
        form.post(route('applications.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Applications', href: route('applications.index') },
                { title: 'New application', href: route('applications.create') },
            ]}
        >
            <Head title="New application" />

            <form onSubmit={submit} className="mx-auto w-full max-w-3xl p-4">
                <h1 className="text-2xl font-semibold tracking-tight">New application</h1>
                <p className="text-muted-foreground mt-1 text-sm">
                    Choose the repository and the cluster. You set the size, the processes, the database, and the env values on the environment page.
                    Nothing deploys until you select Deploy there.
                </p>

                <div className="mt-4">
                    <Row label="Repository" hint="owner/repo, git@github.com:owner/repo.git, or a full https address">
                        <div className="flex gap-2">
                            <Input
                                aria-label="Repository"
                                className="flex-1"
                                placeholder="samir/my-shop"
                                value={data.repository}
                                onChange={(e) => setData('repository', e.target.value)}
                                autoFocus
                            />
                            <Input
                                aria-label="Branch"
                                className="w-32"
                                placeholder={data.deploy_key === 'new' ? 'main' : 'default branch'}
                                value={data.branch}
                                onChange={(e) => setData('branch', e.target.value)}
                            />
                        </div>
                        <InputError message={errors.repository} />
                        <InputError message={errors.branch} />
                        <Collapsible open={data.deploy_key !== 'none'} onOpenChange={(open) => setData('deploy_key', open ? 'new' : 'none')}>
                            <CollapsibleTrigger className="text-muted-foreground hover:text-foreground group flex items-center gap-1 text-xs">
                                <ChevronRight className="size-3.5 transition-transform group-data-[state=open]:rotate-90" />
                                Private repository? Use a deploy key
                            </CollapsibleTrigger>
                            <CollapsibleContent className="mt-2 space-y-2">
                                <div className="flex flex-wrap gap-2">
                                    <Chip selected={data.deploy_key === 'new'} onClick={() => setData('deploy_key', 'new')}>
                                        <span className="font-medium">Make a new key</span>
                                        <span className="text-muted-foreground block text-xs">You add it on GitHub before the first deploy.</span>
                                    </Chip>
                                    <Chip selected={data.deploy_key === 'own'} onClick={() => setData('deploy_key', 'own')}>
                                        <span className="font-medium">Use my own key</span>
                                        <span className="text-muted-foreground block text-xs">GitHub already knows the key.</span>
                                    </Chip>
                                </div>
                                {data.deploy_key === 'new' && (
                                    <p className="text-muted-foreground text-sm">
                                        Opencloud makes a deploy key. The application page then shows the key and the steps to add it on GitHub.
                                        Without a branch, opencloud uses main.
                                    </p>
                                )}
                                {data.deploy_key === 'own' && (
                                    <>
                                        <Textarea
                                            aria-label="Private key"
                                            className="font-mono text-xs"
                                            rows={6}
                                            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                            autoComplete="off"
                                            spellCheck={false}
                                            value={data.deploy_private_key}
                                            onChange={(e) => setData('deploy_private_key', e.target.value)}
                                        />
                                        <p className="text-muted-foreground text-sm">
                                            Paste the private key file, for example ~/.ssh/id_ed25519. The key must not have a passphrase. The page
                                            never shows the key again.
                                        </p>
                                    </>
                                )}
                            </CollapsibleContent>
                        </Collapsible>
                        <InputError message={errors.deploy_private_key} />
                    </Row>

                    <Row label="Cluster">
                        <Select value={data.cluster_id} onValueChange={(value) => setData('cluster_id', value)}>
                            <SelectTrigger className="w-full sm:w-72" aria-label="Cluster">
                                <SelectValue placeholder="Choose a cluster" />
                            </SelectTrigger>
                            <SelectContent>
                                {clusters.map((cluster) => (
                                    <SelectItem key={cluster.id} value={String(cluster.id)}>
                                        {cluster.name}
                                        {cluster.kind === 'local' && ' (dashboard server)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.cluster_id} />
                    </Row>
                </div>

                <div className="mt-6 flex items-center gap-3">
                    <Button
                        disabled={processing || data.repository.trim() === '' || (data.deploy_key === 'own' && data.deploy_private_key.trim() === '')}
                    >
                        {processing ? 'Creating' : 'Create application'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('applications.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
