import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Rocket } from 'lucide-react';
import { useState } from 'react';

/**
 * Starts a deployment and opens its page. A refused start comes back as the error "deploy". The button keeps that error
 * itself, because each poll of the page reloads the shared errors prop, which is empty on the next request.
 */
export default function DeployButton({ environmentId, variant = 'default' }: { environmentId: number; variant?: 'default' | 'secondary' }) {
    const [deploying, setDeploying] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const deploy = () => {
        router.post(
            route('deployments.store', environmentId),
            {},
            {
                preserveState: 'errors',
                onStart: () => {
                    setDeploying(true);
                    setError(null);
                },
                onError: (errors) => setError(errors.deploy ?? null),
                onFinish: () => setDeploying(false),
            },
        );
    };

    return (
        <div className="flex flex-col items-end gap-1">
            <Button variant={variant} onClick={deploy} disabled={deploying}>
                <Rocket />
                {deploying ? 'Starting' : 'Deploy'}
            </Button>
            {error && (
                <p role="alert" className="max-w-xs text-right text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </div>
    );
}
