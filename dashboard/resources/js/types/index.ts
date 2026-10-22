import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    match?: string;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    can: { create_application: boolean; view_audit_log: boolean };
    [key: string]: unknown;
}

export type Role = 'owner' | 'admin' | 'developer';

export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export type DeploymentStatus = 'queued' | 'building' | 'deploying' | 'live' | 'failed' | 'superseded';

export type DnsStatus = 'pending' | 'ok' | 'proxied' | 'wrong' | 'missing';

export type EnvironmentKind = 'permanent' | 'preview' | 'preview_template';

export type Availability = 'runtime' | 'build' | 'both';

export type Size = 'xs' | 's' | 'm' | 'l';

export type SizeOption = { value: Size; cpu_millicores: number; memory_mib: number };

export interface Deployment {
    id: number;
    status: DeploymentStatus;
    commit_sha: string;
    triggered_by: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    warning: string | null;
}

export interface EnvironmentPageProps {
    application: { id: number; name: string; repository: string; type: 'laravel' | 'dockerfile' };
    environment: {
        id: number;
        name: string;
        kind: EnvironmentKind;
        is_protected: boolean;
        branch: string | null;
        auto_deploy: boolean;
        status: DeploymentStatus | null;
        live_commit: string | null;
        urls: string[];
    };
    can: { update: boolean; deploy: boolean; manage_domains: boolean };
    [key: string]: unknown;
}
