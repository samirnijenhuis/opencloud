import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { useState } from 'react';

/** Starts a deployment and opens its page. A refused start comes back as the error "deploy". */
export default function DeployButton({ environmentId, variant = 'default' }: { environmentId: number; variant?: 'default' | 'secondary' }) {
    const [deploying, setDeploying] = useState(false);

    const deploy = () => {
        router.post(route('deployments.store', environmentId), {}, { onStart: () => setDeploying(true), onFinish: () => setDeploying(false) });
    };

    return (
        <Button variant={variant} onClick={deploy} disabled={deploying}>
            <Rocket />
            {deploying ? 'Starting' : 'Deploy'}
        </Button>
    );
}
