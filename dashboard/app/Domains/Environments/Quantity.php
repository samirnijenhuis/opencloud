<?php

namespace App\Domains\Environments;

use InvalidArgumentException;

/**
 * Reads Kubernetes quantities such as 250m, 1, 512Mi, and 1Gi. The metrics API also gives nanocores, such as 1117977n.
 */
final class Quantity
{
    private const FACTORS = [
        'n' => 1e-9, 'u' => 1e-6, 'm' => 1e-3, '' => 1,
        'k' => 1e3, 'M' => 1e6, 'G' => 1e9, 'T' => 1e12, 'P' => 1e15, 'E' => 1e18,
        'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4, 'Pi' => 1024 ** 5, 'Ei' => 1024 ** 6,
    ];

    public static function millicores(string $quantity): int
    {
        return (int) round(self::value($quantity) * 1000);
    }

    public static function mebibytes(string $quantity): int
    {
        return (int) round(self::value($quantity) / 1024 ** 2);
    }

    /** @throws InvalidArgumentException when the text is not a Kubernetes quantity */
    private static function value(string $quantity): float
    {
        if (preg_match('/^(\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(Ki|Mi|Gi|Ti|Pi|Ei|n|u|m|k|M|G|T|P|E)?$/', $quantity, $match) !== 1) {
            throw new InvalidArgumentException("{$quantity} is not a Kubernetes quantity.");
        }

        return (float) $match[1] * self::FACTORS[$match[2] ?? ''];
    }
}
