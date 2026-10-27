export type ShellTokenKind = 'plain' | 'comment' | 'string' | 'variable' | 'flag' | 'operator' | 'command';

export type ShellToken = { text: string; kind: ShellTokenKind };

const WHITESPACE = /[ \t\r]+/y;
const SINGLE_QUOTED = /'[^']*('|$)/y;
const DOUBLE_QUOTED = /"(?:\\[\s\S]|[^"\\])*("|$)/y;
const VARIABLE = /\$(?:\{[^}\n]*\}?|[A-Za-z_][A-Za-z0-9_]*|[0-9?$!@#*-])/y;
const OPERATOR = /&&|\|\||>>|[|;&<>]/y;
const FLAG = /--?[A-Za-z0-9][\w.-]*/y;
const ASSIGNMENT = /[A-Za-z_][A-Za-z0-9_]*=/y;
const WORD = /(?:\\[\s\S]|[^\s&|;<>'"$\\])+/y;

function match(pattern: RegExp, text: string, at: number): string | null {
    pattern.lastIndex = at;
    const found = pattern.exec(text);

    return found === null ? null : found[0];
}

/**
 * Splits shell text into tokens. The tokens joined together are always the same text as the input,
 * because the highlight layer of the editor must line up with the text of the textarea.
 */
export function tokenizeShell(text: string): ShellToken[] {
    const tokens: ShellToken[] = [];
    let at = 0;
    let commandNext = true;

    const push = (value: string, kind: ShellTokenKind) => {
        tokens.push({ text: value, kind });
        at += value.length;
    };

    while (at < text.length) {
        const char = text[at];
        const startsWord = at === 0 || /\s|[&|;<>]/.test(text[at - 1]);

        if (char === '\n') {
            push(char, 'plain');
            commandNext = true;
            continue;
        }

        if (char === '\\' && text[at + 1] === '\n') {
            push('\\\n', 'plain');
            continue;
        }

        const space = match(WHITESPACE, text, at);
        if (space !== null) {
            push(space, 'plain');
            continue;
        }

        if (char === '#' && startsWord) {
            const end = text.indexOf('\n', at);
            push(text.slice(at, end === -1 ? text.length : end), 'comment');
            continue;
        }

        const quoted = match(char === "'" ? SINGLE_QUOTED : DOUBLE_QUOTED, text, at);
        if ((char === "'" || char === '"') && quoted !== null) {
            push(quoted, 'string');
            commandNext = false;
            continue;
        }

        const variable = char === '$' ? match(VARIABLE, text, at) : null;
        if (variable !== null) {
            push(variable, 'variable');
            commandNext = false;
            continue;
        }

        const operator = match(OPERATOR, text, at);
        if (operator !== null) {
            push(operator, 'operator');
            commandNext = operator !== '>' && operator !== '<' && operator !== '>>';
            continue;
        }

        const flag = char === '-' && startsWord && !commandNext ? match(FLAG, text, at) : null;
        if (flag !== null) {
            push(flag, 'flag');
            continue;
        }

        const assignment = commandNext ? match(ASSIGNMENT, text, at) : null;
        if (assignment !== null) {
            push(assignment, 'plain');
            continue;
        }

        const word = match(WORD, text, at);
        if (word !== null) {
            push(word, commandNext ? 'command' : 'plain');
            commandNext = false;
            continue;
        }

        push(char, 'plain');
    }

    return tokens;
}
