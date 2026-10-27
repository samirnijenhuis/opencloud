import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type ProcessKind, type ProcessValues } from '@/types';
import { type ReactNode } from 'react';

type Props = {
    value: ProcessValues;
    onChange: (change: Partial<ProcessValues>) => void;
    errors?: Partial<Record<keyof ProcessValues, string>>;
    disabled?: boolean;
    children?: ReactNode;
};

/** The inputs of one process. A scheduled process also has a schedule. The children go at the end of the row, for example a remove button. */
export default function ProcessFields({ value, onChange, errors = {}, disabled = false, children }: Props) {
    const scheduled = value.kind === 'scheduled';

    return (
        <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    aria-label="Process name"
                    className="w-32 font-mono text-sm"
                    placeholder="worker"
                    value={value.name}
                    disabled={disabled}
                    onChange={(e) => onChange({ name: e.target.value })}
                />
                <Select
                    value={value.kind}
                    disabled={disabled}
                    onValueChange={(kind: ProcessKind) =>
                        onChange(kind === 'scheduled' ? { kind, schedule: value.schedule || '* * * * *' } : { kind })
                    }
                >
                    <SelectTrigger className="w-36" aria-label="Kind">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="long_running">Long-running</SelectItem>
                        <SelectItem value="scheduled">Scheduled</SelectItem>
                    </SelectContent>
                </Select>
                <Input
                    aria-label="Command"
                    className="min-w-48 flex-1 font-mono text-sm"
                    placeholder={scheduled ? 'php artisan schedule:run' : 'php artisan queue:work'}
                    value={value.command}
                    disabled={disabled}
                    onChange={(e) => onChange({ command: e.target.value })}
                />
                {scheduled && (
                    <Input
                        aria-label="Schedule"
                        className="w-32 font-mono text-sm"
                        placeholder="* * * * *"
                        value={value.schedule ?? ''}
                        disabled={disabled}
                        onChange={(e) => onChange({ schedule: e.target.value })}
                    />
                )}
                {children}
            </div>
            <InputError message={errors.name} />
            <InputError message={errors.kind} />
            <InputError message={errors.command} />
            <InputError message={errors.schedule} />
        </div>
    );
}
