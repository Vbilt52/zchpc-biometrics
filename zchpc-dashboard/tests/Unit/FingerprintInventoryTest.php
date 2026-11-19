<?php

namespace Tests\Unit;

use App\Models\FingerprintInventory;
use PHPUnit\Framework\TestCase;

class FingerprintInventoryTest extends TestCase
{
    public function test_it_exposes_only_valid_unique_slot_numbers(): void
    {
        $inventory = new FingerprintInventory(['slots' => [1, '2', 2, 0, 11, -1, 'invalid']]);

        $this->assertSame([1, 2], $inventory->slotNumbers());
    }
}
