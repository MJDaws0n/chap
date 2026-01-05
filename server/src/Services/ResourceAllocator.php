<?php

namespace Chap\Services;

/**
 * Resource allocation with a fixed + auto-split (-1) scheme.
 *
 * At each level, children declare a configured value for each resource:
 * - fixed (>= 0): reserves that exact amount from parent
 * - auto (-1): evenly splits the remaining amount across auto siblings
 */
class ResourceAllocator
{
    /**
     * @param int $parentTotal Parent effective total for this resource (must be >= 0)
     * @param array<int,int|null> $configuredByChildId map childId => configured value (-1 or >=0). null treated as -1
     *
     * @return array{effectiveByChildId: array<int,int>, remainingAfterFixed: int, fixedSum: int, autoCount: int}
     */
    public static function allocateInt(int $parentTotal, array $configuredByChildId): array
    {
        if ($parentTotal < -1) {
            throw new \InvalidArgumentException('parentTotal must be >= 0 or -1 for unlimited');
        }

        // Unlimited parent: fixed values are honored, auto children remain unlimited.
        if ($parentTotal === -1) {
            $effectiveByChildId = [];
            $fixedSum = 0;
            $autoCount = 0;

            foreach ($configuredByChildId as $childId => $configured) {
                $childId = (int)$childId;
                $v = $configured === null ? -1 : (int)$configured;

                if ($v === -1) {
                    $autoCount++;
                    $effectiveByChildId[$childId] = -1;
                    continue;
                }

                if ($v < 0) {
                    throw new \InvalidArgumentException('configured values must be -1 or >= 0');
                }

                $fixedSum += $v;
                $effectiveByChildId[$childId] = $v;
            }

            return [
                'effectiveByChildId' => $effectiveByChildId,
                'remainingAfterFixed' => -1,
                'fixedSum' => $fixedSum,
                'autoCount' => $autoCount,
            ];
        }

        $fixedSum = 0;
        $autoIds = [];

        foreach ($configuredByChildId as $childId => $configured) {
            $v = $configured === null ? -1 : (int)$configured;

            if ($v === -1) {
                $autoIds[] = (int)$childId;
                continue;
            }

            if ($v < 0) {
                throw new \InvalidArgumentException('configured values must be -1 or >= 0');
            }

            $fixedSum += $v;
        }

        $remaining = $parentTotal - $fixedSum;
        if ($remaining < 0) {
            // Fixed allocations exceed parent.
            $remaining = 0;
        }

        $autoCount = count($autoIds);
        $baseShare = $autoCount > 0 ? intdiv($remaining, $autoCount) : 0;
        $remainder = $autoCount > 0 ? ($remaining % $autoCount) : 0;

        $effectiveByChildId = [];

        // Deterministic remainder distribution: lowest child ids get +1.
        sort($autoIds);
        $autoExtra = [];
        for ($i = 0; $i < $autoCount; $i++) {
            $autoExtra[$autoIds[$i]] = $i < $remainder ? 1 : 0;
        }

        foreach ($configuredByChildId as $childId => $configured) {
            $childId = (int)$childId;
            $v = $configured === null ? -1 : (int)$configured;

            if ($v === -1) {
                $effectiveByChildId[$childId] = $baseShare + ($autoExtra[$childId] ?? 0);
            } else {
                $effectiveByChildId[$childId] = max(0, $v);
            }
        }

        return [
            'effectiveByChildId' => $effectiveByChildId,
            'remainingAfterFixed' => max(0, $parentTotal - $fixedSum),
            'fixedSum' => $fixedSum,
            'autoCount' => $autoCount,
        ];
    }

    /**
     * Convenience: validate that fixed allocations do not exceed parent total.
     *
     * @param int $parentTotal
     * @param array<int,int|null> $configuredByChildId
     */
    public static function validateDoesNotOverallocate(int $parentTotal, array $configuredByChildId): bool
    {
        if ($parentTotal === -1) {
            // Unlimited parent: fixed allocations can never exceed.
            foreach ($configuredByChildId as $configured) {
                $v = $configured;
                if ($v === null) {
                    continue;
                }
                $v = (int)$v;
                if ($v < -1) {
                    return false;
                }
            }
            return true;
        }

        $fixedSum = 0;
        foreach ($configuredByChildId as $configured) {
            $v = $configured;
            if ($v === null || (int)$v === -1) {
                continue;
            }
            $v = (int)$v;
            if ($v < 0) {
                return false;
            }
            $fixedSum += $v;
        }
        return $fixedSum <= $parentTotal;
    }
}
