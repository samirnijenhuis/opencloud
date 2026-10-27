import { LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
    /** Shown in place of the link, for example a menu. */
    node?: ReactNode;
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
    can: { create_application: boolean; view_audit_log: boolean; manage_credentials: boolean };
    /** Null for a guest. `update` is a newer release, and only the owner gets it. */
    opencloud: { version: string; update: string | null } | null;
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

export type EnvironmentColor = 'blue' | 'green' | 'orange' | 'purple' | 'red' | 'yellow' | 'sky' | 'gray';

export type EnvironmentLink = { id: number; name: string; kind: EnvironmentKind; color: EnvironmentColor };

export type Availability = 'runtime' | 'build' | 'both';

export type Size = 'xs' | 's' | 'm' | 'l';

export type SizeOption = { value: Size; cpu_millicores: number; memory_mib: number };

/** A long-running process runs all the time as a Deployment. A scheduled process runs on its schedule as a CronJob. */
export type ProcessKind = 'long_running' | 'scheduled';

/** The values of one process in the create form. Each new process takes the size of web and 1 instance. */
export type ProcessValues = { name: string; kind: ProcessKind; command: string; schedule: string | null };

/** The preset of an application. The server stores it as the application type. */
export type ApplicationType = 'laravel' | 'node' | 'python' | 'ruby' | 'static' | 'dockerfile';

export const applicationTypeLabels: Record<ApplicationType, string> = {
    laravel: 'Laravel',
    node: 'Node.js',
    python: 'Python',
    ruby: 'Ruby',
    static: 'Static site',
    dockerfile: 'Dockerfile',
};

/** The env names that a database, Valkey, or a bucket sets: the Laravel names, or URLs that most other frameworks read. */
export type EnvProfileKind = 'laravel' | 'generic';

/** A process of an environment. Only a long-running process has instances, and only a scheduled process has a schedule. */
export type Process = { id: number; name: string; command: string; size: Size } & (
    | { kind: 'long_running'; schedule: null; min_instances: number; max_instances: number; cpu_target: number }
    | { kind: 'scheduled'; schedule: string; min_instances: null; max_instances: null; cpu_target: null }
);

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

export type DeploymentStepStatus = 'finished' | 'running' | 'failed' | 'skipped';

export interface DeploymentStep {
    key: string | null;
    title: string;
    phase: 'build' | 'deploy' | null;
    status: DeploymentStepStatus;
    started_at: string | null;
    finished_at: string | null;
    lines: { offset: number; text: string }[];
}

export interface EnvironmentPageProps {
    application: { id: number; name: string; repository: string; type: ApplicationType; environments: EnvironmentLink[] };
    environment: {
        id: number;
        name: string;
        kind: EnvironmentKind;
        color: EnvironmentColor;
        is_protected: boolean;
        branch: string | null;
        auto_deploy: boolean;
        status: DeploymentStatus | null;
    };
    can: { update: boolean; deploy: boolean; manage_domains: boolean };
    [key: string]: unknown;
}
