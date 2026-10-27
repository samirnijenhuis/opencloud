import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { sizeLabel, sizeText } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Size, type SizeOption } from '@/types';
import { useId } from 'react';

export type Scaling = { min_instances: number; max_instances: number; cpu_target: number };

/** A grid of buttons with a label and a short text each. The pressed button is the value. */
export function Choices<T extends string>({
    options,
    value,
    onChange,
}: {
    options: { value: T; label: string; text: string }[];
    value: T;
    onChange: (value: T) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-2">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'rounded-md border px-3 py-2 text-left text-sm disabled:opacity-60',
                        value === option.value ? 'border-foreground ring-foreground ring-1' : 'enabled:hover:bg-muted',
                    )}
                >
                    <span className="font-medium">{option.label}</span>
                    <span className="text-muted-foreground block text-xs">{option.text}</span>
                </button>
            ))}
        </div>
    );
}

/** The size buttons of web and of each process. */
export function SizePicker({ sizes, value, onChange }: { sizes: SizeOption[]; value: Size; onChange: (size: Size) => void }) {
    return (
        <Choices
            options={sizes.map((size) => ({ value: size.value, label: sizeLabel(size.value), text: sizeText(size) }))}
            value={value}
            onChange={onChange}
        />
    );
}

/** The minimum and maximum instances and the CPU target of web and of each long-running process. */
export function ScalingFields({
    value,
    onChange,
    errors,
}: {
    value: Scaling;
    onChange: (field: keyof Scaling, value: number) => void;
    errors: Partial<Record<keyof Scaling, string>>;
}) {
    const id = useId();

    return (
        <>
            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor={`${id}-min`}>Minimum instances</Label>
                    <Input
                        id={`${id}-min`}
                        type="number"
                        min={1}
                        value={value.min_instances}
                        onChange={(e) => onChange('min_instances', Number(e.target.value))}
                    />
                    <InputError message={errors.min_instances} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${id}-max`}>Maximum instances</Label>
                    <Input
                        id={`${id}-max`}
                        type="number"
                        min={1}
                        value={value.max_instances}
                        onChange={(e) => onChange('max_instances', Number(e.target.value))}
                    />
                    <InputError message={errors.max_instances} />
                </div>
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${id}-cpu`}>CPU target (%)</Label>
                <Input
                    id={`${id}-cpu`}
                    type="number"
                    min={10}
                    max={95}
                    className="w-32"
                    value={value.cpu_target}
                    onChange={(e) => onChange('cpu_target', Number(e.target.value))}
                />
                <InputError message={errors.cpu_target} />
            </div>
        </>
    );
}

/** Instances in words: "1 to 3 at 70% CPU" for a range, or the fixed count. */
export const instancesText = (scaling: Scaling) =>
    scaling.max_instances > scaling.min_instances
        ? `${scaling.min_instances} to ${scaling.max_instances} at ${scaling.cpu_target}% CPU`
        : String(scaling.min_instances);
