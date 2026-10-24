import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type ProcessValues } from '@/types';
import { type ReactNode } from 'react';

type Props = {
    value: ProcessValues;
    onChange: (change: Partial<ProcessValues>) => void;
    errors?: Partial<Record<keyof ProcessValues, string>>;
    disabled?: boolean;
    children?: ReactNode;
};

/** The inputs of one process. The children go at the end of the row, for example a remove button. */
export default function ProcessFields({ value, onChange, errors = {}, disabled = false, children }: Props) {
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
                <Input
                    aria-label="Command"
                    className="min-w-48 flex-1 font-mono text-sm"
                    placeholder="php artisan queue:work"
                    value={value.command}
                    disabled={disabled}
                    onChange={(e) => onChange({ command: e.target.value })}
                />
                <Input
                    aria-label="Instances"
                    type="number"
                    min={1}
                    max={20}
                    className="w-20"
                    value={value.instances}
                    disabled={disabled || value.exclusive}
                    onChange={(e) => onChange({ instances: Number(e.target.value) })}
                />
                <Label className="flex items-center gap-1.5 text-sm font-normal">
                    <Checkbox
                        checked={value.exclusive}
                        disabled={disabled}
                        onCheckedChange={(checked) => onChange(checked === true ? { exclusive: true, instances: 1 } : { exclusive: false })}
                    />
                    Exclusive
                </Label>
                {children}
            </div>
            <InputError message={errors.name} />
            <InputError message={errors.command} />
            <InputError message={errors.instances} />
            <InputError message={errors.exclusive} />
        </div>
    );
}
