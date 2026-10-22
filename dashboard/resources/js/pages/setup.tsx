import NewAccountForm from '@/components/new-account-form';
import AuthLayout from '@/layouts/auth-layout';
import { Head } from '@inertiajs/react';

export default function Setup({ token }: { token: string }) {
    return (
        <AuthLayout title="Create the owner account" description="This account can manage keys, clusters, and members.">
            <Head title="Set up opencloud" />
            <NewAccountForm action={route('setup.store', token)} submitLabel="Create owner account" />
        </AuthLayout>
    );
}
