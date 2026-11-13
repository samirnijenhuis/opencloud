export type RepositoryRead = 'github_app' | 'deploy_key' | 'public' | 'branch_missing' | 'key_refused' | 'unreadable';

export type AppReach = 'installed' | 'not_installed' | 'other_account' | 'no_app' | 'not_github' | 'unknown';

export type PushDelivery = 'github_app' | 'deploy_hook' | 'not_connected' | 'off';

export type PreviewDelivery = 'on' | 'not_connected' | 'off';

/** The JSON of the route applications.repository-access.check. RepositoryAccessController builds it. */
export type RepositoryAccess = {
    repository: string;
    github_repository: string | null;
    github_url: string | null;
    read: RepositoryRead;
    read_works: boolean;
    summary: string;
    default_branch: string | null;
    app: AppReach;
    app_slug: string | null;
    app_owner: string | null;
    install_url: string | null;
    checked_at: string;
};

/** The JSON of the route environments.repository-access. */
export type EnvironmentRepositoryAccess = RepositoryAccess & {
    branch: string | null;
    push: PushDelivery | null;
    previews: PreviewDelivery;
    deploy_public_key: string | null;
    can_update: boolean;
    can_manage_credentials: boolean;
};
