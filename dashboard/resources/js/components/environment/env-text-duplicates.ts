import { type EnvVar } from './env-values';

const KEY = /^[A-Za-z_][A-Za-z0-9_]*$/;

/** The key of one dotenv line, read as DotenvText::parse() reads it on the server. Null when the line sets no valid key. */
function dotenvKey(raw: string): string | null {
    const line = raw.trim();

    if (line === '' || line.startsWith('#')) {
        return null;
    }

    const rest = line.replace(/^export\s+/, '');
    const equals = rest.indexOf('=');
    const key = equals === -1 ? '' : rest.slice(0, equals).trimEnd();

    return KEY.test(key) ? key : null;
}

/**
 * The page lines that repeat an earlier key, in page order. The locked rows are lines 1 to locked.length, and the text
 * lines follow them. A text line maps to the message that a save gives. A locked row maps to null, because a save
 * never refuses it.
 */
export function duplicateLines(locked: EnvVar[], text: string): Map<number, string | null> {
    const first = new Map<string, number>();
    const duplicates = new Map<number, string | null>();

    locked.forEach((envVar, index) => {
        if (first.has(envVar.key)) {
            duplicates.set(index + 1, null);
        } else {
            first.set(envVar.key, index + 1);
        }
    });

    text.split('\n').forEach((raw, index) => {
        const line = locked.length + index + 1;
        const key = dotenvKey(raw);

        if (key === null) {
            return;
        }

        const earlier = first.get(key);

        if (earlier === undefined) {
            first.set(key, line);
        } else if (earlier > locked.length) {
            duplicates.set(line, `Line ${line} repeats the key ${key} from line ${earlier}.`);
        } else {
            const resource = locked.some((envVar) => envVar.key === key && envVar.source === 'datastore');
            duplicates.set(
                line,
                resource
                    ? `Line ${line} uses ${key}. A resource sets this key.`
                    : `Line ${line} uses ${key}. This key holds a secret. Replace it in the table.`,
            );
            // A save names this line, not the locked row, when a later line repeats the key.
            first.set(key, line);
        }
    });

    return duplicates;
}
