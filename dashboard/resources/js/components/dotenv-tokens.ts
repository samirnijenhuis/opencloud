export type DotenvTokenKind = 'plain' | 'comment' | 'keyword' | 'key' | 'operator' | 'value' | 'string' | 'variable' | 'invalid';

export type DotenvToken = { text: string; kind: DotenvTokenKind };

const SPACES = /[ \t\r]+/y;
const KEY = /[A-Za-z_][A-Za-z0-9_]*/y;
const EXPORT = /export(?=[ \t]+\S)/y;
const SINGLE_QUOTED = /'[^'\n]*('|$)/y;
const DOUBLE_QUOTED = /"(?:\\.|[^"\\\n])*("|$)/y;
const VARIABLE = /\$\{[^}\n]*\}?/g;
const UNQUOTED = /(?:[^\s#]|(?<=\S)#|[ \t]+(?=[^\s#]))+/y;

function match(pattern: RegExp, text: string, at: number): string | null {
    pattern.lastIndex = at;
    const found = pattern.exec(text);

    return found === null ? null : found[0];
}

/**
 * Splits dotenv text into tokens. The tokens joined together are always the same text as the input,
 * because the highlight layer of the editor must line up with the text of the textarea.
 */
export function tokenizeDotenv(text: string): DotenvToken[] {
    const tokens: DotenvToken[] = [];
    let at = 0;

    const push = (value: string, kind: DotenvTokenKind) => {
        if (value !== '') {
            tokens.push({ text: value, kind });
            at += value.length;
        }
    };

    const spaces = () => push(match(SPACES, text, at) ?? '', 'plain');

    const restOfLine = (kind: DotenvTokenKind) => {
        const end = text.indexOf('\n', at);
        push(text.slice(at, end === -1 ? text.length : end), kind);
    };

    const quoted = (kind: DotenvTokenKind, value: string) => {
        if (kind !== 'string' || value[0] !== '"') {
            push(value, kind);
            return;
        }
        let last = 0;
        for (const found of value.matchAll(VARIABLE)) {
            push(value.slice(last, found.index), 'string');
            push(found[0], 'variable');
            last = found.index + found[0].length;
        }
        push(value.slice(last), 'string');
    };

    while (at < text.length) {
        if (text[at] === '\n') {
            push('\n', 'plain');
            continue;
        }

        spaces();

        if (text[at] === '#') {
            restOfLine('comment');
            continue;
        }

        if (at >= text.length || text[at] === '\n') {
            continue;
        }

        push(match(EXPORT, text, at) ?? '', 'keyword');
        spaces();

        const key = match(KEY, text, at);
        if (key === null) {
            restOfLine('invalid');
            continue;
        }
        push(key, 'key');
        spaces();

        if (text[at] !== '=') {
            restOfLine('invalid');
            continue;
        }
        push('=', 'operator');
        spaces();

        const char = text[at];
        const string = char === '"' ? match(DOUBLE_QUOTED, text, at) : char === "'" ? match(SINGLE_QUOTED, text, at) : null;
        if (string !== null) {
            quoted('string', string);
        } else {
            push(match(UNQUOTED, text, at) ?? '', 'value');
        }
        spaces();

        if (text[at] === '#') {
            restOfLine('comment');
        } else if (at < text.length && text[at] !== '\n') {
            restOfLine('invalid');
        }
    }

    return tokens;
}
