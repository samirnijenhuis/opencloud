import { cn } from '@/lib/utils';
import { type ApplicationType, type EnvProfileKind, type ProcessValues } from '@/types';

/** The defaults of one preset, from the server. */
export type Preset = {
    value: ApplicationType;
    port: number;
    chooses_port: boolean;
    uses_build_command: boolean;
    deploy_commands: string[];
    processes: ProcessValues[];
    database: 'mysql' | 'postgres' | 'none';
    env_profile: EnvProfileKind;
};

export const presetText: Record<ApplicationType, { hint: string; buildCommand: string }> = {
    laravel: {
        hint: 'FrankenPHP, a queue worker, and a scheduler',
        buildCommand: 'Optional extra step after the build, for example php artisan icons:cache',
    },
    node: {
        hint: 'The build and start scripts of package.json',
        buildCommand: 'Optional extra step after the build script, for example npx prisma generate',
    },
    python: {
        hint: 'A Procfile, or Django, Flask, or FastAPI',
        buildCommand: 'Optional extra step after the install, for example python manage.py collectstatic --noinput',
    },
    static: {
        hint: 'nginx serves the files or the build output',
        buildCommand: 'Not used. The build script of package.json builds the site.',
    },
    dockerfile: {
        hint: 'The Dockerfile of the repository',
        buildCommand: 'Not used. The Dockerfile builds the image.',
    },
};

export const envProfiles: { value: EnvProfileKind; label: string; hint: string }[] = [
    { value: 'laravel', label: 'Laravel names', hint: 'DB_HOST, REDIS_HOST, AWS_BUCKET' },
    { value: 'generic', label: 'URLs', hint: 'DATABASE_URL, REDIS_URL, AWS_BUCKET' },
];

export function Chip({ selected, onClick, children }: { selected: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={cn(
                'rounded-md border px-3 py-2 text-left text-sm transition-colors disabled:pointer-events-none disabled:opacity-60',
                selected ? 'border-primary bg-primary/5 ring-primary ring-1' : 'hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}
