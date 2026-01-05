<?php

namespace Tests\Unit;

use Tests\TestCase;
use Chap\Services\ResourceAllocator;

class ResourceAllocatorTest extends TestCase
{
    public function testAutoSplitEven(): void
    {
        $result = ResourceAllocator::allocateInt(4096, [
            1 => -1,
            2 => -1,
        ]);

        $this->assertSame(2048, $result['effectiveByChildId'][1]);
        $this->assertSame(2048, $result['effectiveByChildId'][2]);
    }

    public function testMixedFixedAndAuto(): void
    {
        $result = ResourceAllocator::allocateInt(4096, [
            10 => 2048,
            11 => -1,
            12 => -1,
        ]);

        $this->assertSame(2048, $result['effectiveByChildId'][10]);
        $this->assertSame(1024, $result['effectiveByChildId'][11]);
        $this->assertSame(1024, $result['effectiveByChildId'][12]);
    }

    public function testAutoSplitRemainderDistributedDeterministically(): void
    {
        $result = ResourceAllocator::allocateInt(5, [
            1 => -1,
            2 => -1,
        ]);

        // 5 split across 2 => 2 + 3 (remainder goes to lowest id)
        $this->assertSame(3, $result['effectiveByChildId'][1]);
        $this->assertSame(2, $result['effectiveByChildId'][2]);
    }
}
