import { type DotenvTokenKind, tokenizeDotenv } from '@/components/dotenv-tokens';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useRef } from 'react';
import { type EnvVar } from './env-values';

const kindClasses: Record<DotenvTokenKind, string> = {
    plain: '',
    comment: 'text-muted-foreground italic',
    keyword: 'text-violet-700 dark:text-violet-300',
    key: 'text-sky-700 dark:text-sky-400',
    operator: 'text-rose-600 dark:text-rose-400',
    value: '',
    string: 'text-emerald-700 dark:text-emerald-400',
    variable: 'text-amber-700 dark:text-amber-400',
    invalid: 'text-destructive underline decoration-wavy',
};

const LINE_HEIGHT = 20;
const BOTTOM_PADDING = 16;
// The pre and the textarea share every property that changes where a character sits.
const textLayout = 'm-0 box-border w-full px-3 pt-0 pb-4 font-mono text-sm leading-5 whitespace-pre';

function lineNumber(number: number, failed: boolean) {
    return (
        <div
            key={number}
            className={cn('h-5 pr-3 text-right text-xs leading-5 select-none', failed ? 'text-destructive font-semibold' : 'text-muted-foreground')}
        >
            {number}
        </div>
    );
}

/** The env values as dotenv text. The values that a resource sets and the secrets are locked rows above the text. */
export function EnvTextEditor({
    environmentId,
    envVars,
    envText,
    canUpdate,
}: {
    environmentId: number;
    envVars: EnvVar[];
    envText: string;
    canUpdate: boolean;
}) {
    const form = useForm<{ text: string }>({ text: envText });
    const { data, setData, errors, processing } = form;
    const locked = envVars.filter((envVar) => envVar.source === 'datastore' || envVar.is_secret);
    const tokens = useMemo(() => tokenizeDotenv(data.text), [data.text]);
    const highlight = useRef<HTMLPreElement>(null);
    const messages = errors.text === undefined ? [] : errors.text.split('\n');
    const failedLines = new Set(messages.map((message) => Number(/^Line (\d+)/.exec(message)?.[1])));
    const lineCount = data.text.split('\n').length;

    useEffect(() => {
        setData('text', envText);
        // Only a new saved text resets the editor. Typing must not.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [envText]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('env-text.update', environmentId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="border-input bg-background focus-within:ring-ring rounded-md border py-2 focus-within:ring-2 focus-within:ring-offset-2">
                {locked.map((envVar, index) => (
                    <div key={envVar.id} className="bg-muted/50 text-muted-foreground flex h-5 items-center font-mono text-sm leading-5">
                        <div
                            className={cn(
                                'w-12 shrink-0 pr-3 text-right text-xs select-none',
                                failedLines.has(index + 1) && 'text-destructive font-semibold',
                            )}
                        >
                            {index + 1}
                        </div>
                        <Lock className="mr-2 size-3 shrink-0" aria-hidden="true" />
                        <span className="font-medium">{envVar.key}</span>
                        <span className="ml-3 truncate italic">
                            {envVar.source === 'datastore' ? `Set by ${envVar.resource ?? 'a resource'}` : 'Secret'}
                        </span>
                        <span className="sr-only">. Locked.</span>
                    </div>
                ))}
                <div className="flex">
                    <div className="w-12 shrink-0">
                        {Array.from({ length: lineCount }, (_, index) =>
                            lineNumber(locked.length + index + 1, failedLines.has(locked.length + index + 1)),
                        )}
                    </div>
                    <div className="relative min-w-0 flex-1">
                        <pre ref={highlight} aria-hidden="true" className={cn(textLayout, 'pointer-events-none absolute inset-0 overflow-hidden')}>
                            {tokens.map((token, index) => (
                                <span key={index} className={kindClasses[token.kind]}>
                                    {token.text}
                                </span>
                            ))}
                            {'\n'}
                        </pre>
                        <textarea
                            aria-label="Env values as text"
                            value={data.text}
                            readOnly={!canUpdate}
                            spellCheck={false}
                            autoCapitalize="off"
                            autoCorrect="off"
                            autoComplete="off"
                            placeholder="KEY=value"
                            style={{ height: lineCount * LINE_HEIGHT + BOTTOM_PADDING }}
                            onChange={(e) => setData('text', e.target.value)}
                            onScroll={(e) => {
                                if (highlight.current !== null) {
                                    highlight.current.scrollLeft = e.currentTarget.scrollLeft;
                                }
                            }}
                            className={cn(
                                textLayout,
                                'caret-foreground placeholder:text-muted-foreground relative block resize-none overflow-x-auto overflow-y-hidden bg-transparent text-transparent outline-hidden',
                            )}
                        />
                    </div>
                </div>
            </div>
            {messages.length > 0 && (
                <ul className="text-destructive space-y-1 text-sm" role="alert">
                    {messages.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            )}
            <p className="text-muted-foreground text-sm">Opencloud does not save comments. Add a secret in the table.</p>
            {canUpdate ? (
                <Button disabled={processing}>Save values</Button>
            ) : (
                <p className="text-muted-foreground text-sm">Only an admin or an owner can change the values of a protected environment.</p>
            )}
        </form>
    );
}
