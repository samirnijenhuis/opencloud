import NewAccountForm from '@/components/new-account-form';
import AuthLayout from '@/layouts/auth-layout';
import { type Role } from '@/types';
import { Head } from '@inertiajs/react';

export default function AcceptInvite({ token, role }: { token: string; role: Role }) {
    return (
        <AuthLayout title="Join opencloud" description={`You are invited as ${role === 'admin' ? 'an' : 'a'} ${role}. Create your account.`}>
            <Head title="Accept invite" />
            <NewAccountForm action={route('invites.accept', token)} submitLabel="Create account" />
        </AuthLayout>
    );
}
