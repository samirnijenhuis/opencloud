import { environmentColors } from '@/components/environment-color';
import { DeployHook } from '@/components/environment/deploy-hook';
import { EnvValues, type EnvVar } from '@/components/environment/env-values';
import InputError from '@/components/input-error';
import { ShellEditor } from '@/components/shell-editor';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EnvironmentLayout from '@/layouts/environment-layout';
import { cn } from '@/lib/utils';
import { type EnvironmentColor, type EnvironmentPageProps } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, type ReactNode, useEffect, useState } from 'react';

type Settings = {
    branch: string;
    auto_deploy: boolean;
    build_command: string | null;
    deploy_commands: string[];
};

type Props = EnvironmentPageProps & { settings: Settings; envVars: EnvVar[] };

const sections = [
    { id: 'general', title: 'General' },
    { id: 'deployments', title: 'Deployments' },
    { id: 'environment-values', title: 'Environment values' },
] as const;

const LOCKED_REASON = 'Only an admin or an owner can change a protected environment.';

/** The active section is the last one whose heading is above a line near the top of the screen, or the last one at the end of the page. */
function useActiveSection(): string {
    const [active, setActive] = useState<string>(sections[0].id);

    useEffect(() => {
        const update = () => {
            const line = window.innerHeight * 0.3;
            const atEnd = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;
            const passed = sections.filter(({ id }) => (document.getElementById(id)?.getBoundingClientRect().top ?? Infinity) <= line);

            setActive(atEnd ? sections[sections.length - 1].id : (passed[passed.length - 1] ?? sections[0]).id);
        };

        update();
        window.addEventListener('scroll', update, { passive: true });

        return () => window.removeEventListener('scroll', update);
    }, []);

    return active;
}

/** On a first load Inertia scrolls to the top after the page renders, so the page goes to the hash after that, once. */
function useScrollToHash() {
    useEffect(() => {
        const stop = router.on('navigate', () => {
            stop();
            document.getElementById(window.location.hash.slice(1))?.scrollIntoView();
        });

        return stop;
    }, []);
}

function Section({ id, title, description, children }: { id: string; title: string; description: string; children: ReactNode }) {
    return (
        <section id={id} className="scroll-mt-6 space-y-4">
            <div className="space-y-1">
                <h2 className="text-base font-medium">{title}</h2>
                <p className="text-muted-foreground text-sm">{description}</p>
            </div>
            {children}
        </section>
    );
}

function Field({ label, description, htmlFor, children }: { label: string; description: string; htmlFor?: string; children: ReactNode }) {
    return (
        <div className="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-center sm:gap-6">
            <div className="space-y-1">
                <Label htmlFor={htmlFor}>{label}</Label>
                <p className="text-muted-foreground text-sm">{description}</p>
            </div>
            <div className="min-w-0">{children}</div>
        </div>
    );
}

function GeneralForm({ environment, settings, locked }: { environment: Props['environment']; settings: Settings; locked: boolean }) {
    const form = useForm({ branch: settings.branch, auto_deploy: settings.auto_deploy });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('environments.update', environment.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const setColor = (color: EnvironmentColor) =>
        router.patch(route('environments.color.update', environment.id), { color }, { preserveScroll: true });

    return (
        <form onSubmit={submit} className="divide-y rounded-lg border">
            <fieldset disabled={locked} className="divide-y">
                <Field label="Branch" description="The Git branch that this environment deploys." htmlFor="branch">
                    <Input id="branch" className="font-mono" value={form.data.branch} onChange={(e) => form.setData('branch', e.target.value)} />
                    <InputError message={form.errors.branch} />
                </Field>
                <Field label="Deploy on push" description="Deploy each new commit on the branch." htmlFor="auto_deploy">
                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox
                            id="auto_deploy"
                            checked={form.data.auto_deploy}
                            onCheckedChange={(checked) => form.setData('auto_deploy', checked === true)}
                        />
                        {form.data.auto_deploy ? 'On' : 'Off'}
                    </Label>
                </Field>
                <Field label="Color" description="The color that tells this environment apart in the dashboard.">
                    <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Environment color">
                        {(Object.keys(environmentColors) as EnvironmentColor[]).map((color) => (
                            <button
                                key={color}
                                type="button"
                                role="radio"
                                aria-checked={environment.color === color}
                                aria-label={color}
                                title={color}
                                onClick={() => setColor(color)}
                                className={cn(
                                    'focus-visible:ring-ring grid size-7 place-items-center rounded-md border outline-none focus-visible:ring-2 disabled:opacity-50',
                                    environment.color === color ? 'border-foreground' : 'border-transparent',
                                )}
                            >
                                <span className={cn('size-4 rounded-[4px]', environmentColors[color])} />
                            </button>
                        ))}
                    </div>
                </Field>
            </fieldset>
            <Field label="Protection" description="A protected environment keeps its secrets from developers.">
                <p className="text-sm">
                    {environment.is_protected
                        ? 'Protected. Only an admin or an owner can change or deploy it.'
                        : 'Not protected. Each member can change and deploy it.'}
                </p>
            </Field>
            {!locked && (
                <div className="flex items-center justify-end gap-3 p-4">
                    {form.recentlySuccessful && <span className="text-muted-foreground text-sm">Saved.</span>}
                    <Button disabled={!form.isDirty || form.processing}>Save</Button>
                </div>
            )}
        </form>
    );
}

function DeploymentsForm({ environmentId, settings, locked }: { environmentId: number; settings: Settings; locked: boolean }) {
    const form = useForm({ build_command: settings.build_command ?? '', deploy_commands: settings.deploy_commands.join('\n') });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((current) => ({ ...current, deploy_commands: current.deploy_commands.split('\n') }));
        form.patch(route('environments.update', environmentId), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <form onSubmit={submit} className="divide-y rounded-lg border">
            <fieldset disabled={locked} className="divide-y">
                <Field
                    label="Build command"
                    description="Runs in the image build. One command per line. The build stops at the first command that fails. A line that starts with # is a comment."
                    htmlFor="build_command"
                >
                    <ShellEditor
                        id="build_command"
                        rows={2}
                        placeholder="npm run build"
                        value={form.data.build_command}
                        onChange={(value) => form.setData('build_command', value)}
                    />
                    <InputError message={form.errors.build_command} />
                </Field>
                <Field
                    label="Deploy commands"
                    description="Run before the new version gets traffic. One command per line. A line that starts with # is a comment."
                    htmlFor="deploy_commands"
                >
                    <ShellEditor
                        id="deploy_commands"
                        rows={3}
                        value={form.data.deploy_commands}
                        onChange={(value) => form.setData('deploy_commands', value)}
                    />
                    <InputError message={form.errors.deploy_commands} />
                </Field>
            </fieldset>
            {!locked && (
                <div className="flex flex-wrap items-center justify-end gap-3 p-4">
                    <span className="text-muted-foreground mr-auto text-xs">Changes apply on the next deploy.</span>
                    {form.recentlySuccessful && <span className="text-muted-foreground text-sm">Saved.</span>}
                    <Button disabled={!form.isDirty || form.processing}>Save</Button>
                </div>
            )}
        </form>
    );
}

export default function EnvironmentSettings(props: Props) {
    const { environment, settings, envVars, can } = props;
    const locked = !can.update;
    const active = useActiveSection();
    useScrollToHash();

    return (
        <EnvironmentLayout page={props} tab="settings" title="Settings">
            <div className="grid gap-6 lg:grid-cols-[11rem_minmax(0,1fr)] lg:gap-10">
                <nav aria-label="Settings sections" className="lg:sticky lg:top-6 lg:self-start">
                    <ul className="flex gap-1 overflow-x-auto [scrollbar-width:none] lg:flex-col">
                        {sections.map((section) => (
                            <li key={section.id}>
                                <a
                                    href={`#${section.id}`}
                                    aria-current={active === section.id ? 'true' : undefined}
                                    className={cn(
                                        'block rounded-md px-3 py-1.5 text-sm whitespace-nowrap',
                                        active === section.id ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {section.title}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="min-w-0 space-y-12">
                    {locked && <p className="text-muted-foreground text-sm">{LOCKED_REASON}</p>}

                    <Section id="general" title="General" description="What this environment deploys and how it looks in the dashboard.">
                        <GeneralForm environment={environment} settings={settings} locked={locked} />
                    </Section>

                    <Section id="deployments" title="Deployments" description="The commands that build and release a new version.">
                        <DeploymentsForm environmentId={environment.id} settings={settings} locked={locked} />
                        {environment.kind !== 'preview_template' && <DeployHook environmentId={environment.id} canUpdate={can.update} />}
                    </Section>

                    <Section
                        id="environment-values"
                        title="Environment values"
                        description="The env values of the app. Changes apply on the next deploy."
                    >
                        <EnvValues environmentId={environment.id} envVars={envVars} canUpdate={can.update} />
                    </Section>
                </div>
            </div>
        </EnvironmentLayout>
    );
}
