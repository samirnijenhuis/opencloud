import { cn } from '@/lib/utils';
import { type ComponentProps, useLayoutEffect, useMemo, useRef } from 'react';
import { type ShellTokenKind, tokenizeShell } from './shell-tokens';

const kindClasses: Record<ShellTokenKind, string> = {
    plain: '',
    comment: 'text-muted-foreground italic',
    string: 'text-emerald-700 dark:text-emerald-400',
    variable: 'text-sky-700 dark:text-sky-400',
    flag: 'text-amber-700 dark:text-amber-400',
    operator: 'text-rose-600 dark:text-rose-400',
    command: 'text-violet-700 dark:text-violet-300',
};

// The pre and the textarea share every property that changes where a character sits.
const textLayout =
    'm-0 box-border w-full whitespace-pre-wrap break-words px-3 py-2 font-mono text-sm leading-5 [overflow-wrap:anywhere] [scrollbar-gutter:stable]';
const LINE_HEIGHT = 20;
const VERTICAL_PADDING = 16;
const MAX_HEIGHT = 320;

type Props = Omit<ComponentProps<'textarea'>, 'value' | 'onChange' | 'rows'> & {
    value: string;
    onChange: (value: string) => void;
    rows?: number;
};

/** A textarea with shell highlighting. The highlighted text sits in a pre behind a textarea with transparent text. */
export function ShellEditor({ value, onChange, rows = 3, className, disabled, ...props }: Props) {
    const textarea = useRef<HTMLTextAreaElement>(null);
    const highlight = useRef<HTMLPreElement>(null);
    const tokens = useMemo(() => tokenizeShell(value), [value]);
    const minHeight = rows * LINE_HEIGHT + VERTICAL_PADDING;

    useLayoutEffect(() => {
        const field = textarea.current;
        if (field === null) {
            return;
        }
        field.style.height = 'auto';
        field.style.height = `${Math.min(Math.max(field.scrollHeight, minHeight), MAX_HEIGHT)}px`;
        if (highlight.current !== null) {
            highlight.current.scrollTop = field.scrollTop;
        }
    }, [value, minHeight]);

    return (
        <div
            className={cn(
                'border-input bg-background focus-within:ring-ring relative rounded-md border focus-within:ring-2 focus-within:ring-offset-2',
                disabled && 'cursor-not-allowed opacity-50',
                className,
            )}
        >
            <pre ref={highlight} aria-hidden="true" className={cn(textLayout, 'pointer-events-none absolute inset-0 overflow-hidden')}>
                {tokens.map((token, index) => (
                    <span key={index} className={kindClasses[token.kind]}>
                        {token.text}
                    </span>
                ))}
                {'\n'}
            </pre>
            <textarea
                {...props}
                ref={textarea}
                value={value}
                disabled={disabled}
                spellCheck={false}
                autoCapitalize="off"
                autoCorrect="off"
                style={{ minHeight, maxHeight: MAX_HEIGHT }}
                onChange={(e) => onChange(e.target.value)}
                onScroll={(e) => {
                    if (highlight.current !== null) {
                        highlight.current.scrollTop = e.currentTarget.scrollTop;
                    }
                }}
                className={cn(
                    textLayout,
                    'caret-foreground placeholder:text-muted-foreground relative block resize-none overflow-y-auto bg-transparent text-transparent outline-hidden disabled:cursor-not-allowed',
                )}
            />
        </div>
    );
}
