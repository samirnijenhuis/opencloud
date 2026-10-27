<?php

namespace Tests\Domains\Environments;

use App\Domains\Environments\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function cpu(): array
    {
        return [
            'millicores' => ['250m', 250],
            'one core' => ['1', 1000],
            'half a core' => ['0.5', 500],
            'cores of a node' => ['18', 18000],
            'nanocores from the metrics API' => ['1117977n', 1],
            'nanocores that round up' => ['2500000n', 3],
            'microcores' => ['1500u', 2],
        ];
    }

    /** @return array<string, array{string, int}> */
    public static function memory(): array
    {
        return [
            'mebibytes' => ['512Mi', 512],
            'one gibibyte' => ['1Gi', 1024],
            'kibibytes from the metrics API' => ['31364Ki', 31],
            'kibibytes of a node' => ['16422380Ki', 16037],
            'decimal gigabytes' => ['1G', 954],
            'plain bytes' => ['134217728', 128],
            'an exponent' => ['1e9', 954],
        ];
    }

    #[DataProvider('cpu')]
    public function test_cpu_quantities_become_millicores(string $quantity, int $millicores): void
    {
        $this->assertSame($millicores, Quantity::millicores($quantity));
    }

    #[DataProvider('memory')]
    public function test_memory_quantities_become_mebibytes(string $quantity, int $mebibytes): void
    {
        $this->assertSame($mebibytes, Quantity::mebibytes($quantity));
    }

    public function test_text_that_is_not_a_quantity_is_refused(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('12 cores is not a Kubernetes quantity.'));

        Quantity::millicores('12 cores');
    }
}
