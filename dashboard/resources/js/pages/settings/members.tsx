import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type Role } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type Member = {
    id: number;
    name: string;
    email: string;
    role: Role;
    can_manage: boolean;
};

type PendingInvite = {
    id: number;
    role: Role;
    expires_at: string;
};

type MembersProps = {
    members: Member[];
    roles: Role[];
    canInvite: boolean;
    invites: PendingInvite[];
    inviteLink: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Members',
        href: '/settings/members',
    },
];

function RoleSelect({ id, value, roles, onChange }: { id: string; value: Role; roles: Role[]; onChange: (role: Role) => void }) {
    return (
        <Select value={value} onValueChange={(role) => onChange(role as Role)}>
            <SelectTrigger id={id} className="w-36">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {roles.map((role) => (
                    <SelectItem key={role} value={role}>
                        {role}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function InviteLink({ link }: { link: string }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard.writeText(link).then(() => setCopied(true));
    };

    return (
        <div className="space-y-2 rounded-lg border p-4">
            <Label htmlFor="invite_link">Copy this invite link now. You cannot see it again. It works once and expires in 7 days.</Label>
            <div className="flex gap-2">
                <Input id="invite_link" readOnly value={link} onFocus={(e) => e.target.select()} />
                <Button type="button" variant="secondary" onClick={copy}>
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}

export default function Members({ members, roles, canInvite, invites, inviteLink }: MembersProps) {
    const { data, setData, post, processing, errors } = useForm<{ role: Role }>({ role: 'developer' });

    const createInvite: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('invites.store'), { preserveScroll: true });
    };

    const changeRole = (member: Member, role: Role) => {
        router.patch(route('members.update', member.id), { role }, { preserveScroll: true });
    };

    const removeMember = (member: Member) => {
        if (confirm(`Remove ${member.name} from this dashboard?`)) {
            router.delete(route('members.destroy', member.id), { preserveScroll: true });
        }
    };

    const revokeInvite = (invite: PendingInvite) => {
        router.delete(route('invites.destroy', invite.id), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Members" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Members" description="The people who can use this dashboard, and their roles" />

                    <ul className="divide-y rounded-lg border">
                        {members.map((member) => (
                            <li key={member.id} className="flex items-center justify-between gap-4 p-4">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">{member.name}</p>
                                    <p className="text-muted-foreground truncate text-sm">{member.email}</p>
                                </div>
                                {member.can_manage ? (
                                    <div className="flex items-center gap-2">
                                        <RoleSelect
                                            id={`role-${member.id}`}
                                            value={member.role}
                                            roles={roles}
                                            onChange={(role) => changeRole(member, role)}
                                        />
                                        <Button variant="ghost" size="sm" onClick={() => removeMember(member)}>
                                            Remove
                                        </Button>
                                    </div>
                                ) : (
                                    <span className="text-muted-foreground text-sm">{member.role}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>

                {canInvite && (
                    <div className="space-y-6">
                        <HeadingSmall title="Invite a member" description="Create a link and send it to the person yourself" />

                        {inviteLink && <InviteLink link={inviteLink} />}

                        <form onSubmit={createInvite} className="flex items-end gap-2">
                            <div className="grid gap-2">
                                <Label htmlFor="invite_role">Role</Label>
                                <RoleSelect id="invite_role" value={data.role} roles={roles} onChange={(role) => setData('role', role)} />
                                <InputError message={errors.role} />
                            </div>
                            <Button disabled={processing}>Create invite link</Button>
                        </form>

                        {invites.length > 0 && (
                            <ul className="divide-y rounded-lg border">
                                {invites.map((invite) => (
                                    <li key={invite.id} className="flex items-center justify-between gap-4 p-4 text-sm">
                                        <span>
                                            Open invite for a {invite.role}. It expires on {new Date(invite.expires_at).toLocaleDateString()}.
                                        </span>
                                        <Button variant="ghost" size="sm" onClick={() => revokeInvite(invite)}>
                                            Revoke
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
