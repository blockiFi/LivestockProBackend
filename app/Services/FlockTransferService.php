<?php

namespace App\Services;

use App\Models\Flock;
use App\Models\Farm;
use App\Models\FlockHouseAllocation;
use App\Models\FlockTransfer;
use App\Models\FlockTransferLine;
use App\Models\PoultryHouse;
use App\Services\HouseCapacityService;
use App\Services\HouseStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FlockTransferService
{
    /**
     * Apply a transfer (move/split/merge) for a flock.
     *
     * Payload format:
     * - transfer_date: Y-m-d
     * - note?: string
     * - lines: array of { from_house_id?: int|null, to_house_id?: int|null, quantity: int }
     */
    public function apply(Farm $farm, Flock $flock, array $payload, ?int $userId = null): FlockTransfer
    {
        if ($flock->farm_id !== $farm->id) {
            throw ValidationException::withMessages(['flock_id' => ['Flock does not belong to this farm.']]);
        }

        $lines = $payload['lines'] ?? [];
        if (!is_array($lines) || count($lines) < 1) {
            throw ValidationException::withMessages(['lines' => ['At least one transfer line is required.']]);
        }

        // Normalize and validate line quantities
        foreach ($lines as $idx => $line) {
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                throw ValidationException::withMessages(["lines.$idx.quantity" => ['Quantity must be greater than 0.']]);
            }
            if (empty($line['from_house_id']) && empty($line['to_house_id'])) {
                throw ValidationException::withMessages(["lines.$idx" => ['Either from_house_id or to_house_id is required.']]);
            }
        }

        return DB::transaction(function () use ($farm, $flock, $payload, $lines, $userId) {
            $capacityService = app(HouseCapacityService::class);

            // Keep allocation rows aligned with birds still alive (mortality/culls).
            $flock->reconcileHouseAllocations();

            // Build current allocation map for flock
            /** @var array<int,int> $current */
            $current = $flock->currentHouseAllocations();

            // Compute outgoing totals per from_house_id
            $outgoing = [];
            foreach ($lines as $line) {
                $from = $line['from_house_id'] ?? null;
                if ($from) {
                    $from = (int) $from;
                    $outgoing[$from] = ($outgoing[$from] ?? 0) + (int) $line['quantity'];
                }
            }

            foreach ($outgoing as $houseId => $qtyOut) {
                $available = (int) ($current[$houseId] ?? 0);
                if ($qtyOut > $available) {
                    throw ValidationException::withMessages([
                        'lines' => ["Not enough birds in house_id=$houseId. Available=$available, requested_out=$qtyOut."],
                    ]);
                }
            }

            // Apply changes to an in-memory allocation map
            $next = $current;
            foreach ($lines as $line) {
                $qty = (int) $line['quantity'];
                $from = $line['from_house_id'] ?? null;
                $to = $line['to_house_id'] ?? null;

                if ($from) {
                    $from = (int) $from;
                    $next[$from] = (int) ($next[$from] ?? 0) - $qty;
                }
                if ($to) {
                    $to = (int) $to;
                    $next[$to] = (int) ($next[$to] ?? 0) + $qty;
                }
            }

            // Ensure no negatives
            foreach ($next as $houseId => $qty) {
                if ($qty < 0) {
                    throw ValidationException::withMessages([
                        'lines' => ["Allocation would become negative for house_id=$houseId."],
                    ]);
                }
            }

            // Capacity validation (age-based) for destination houses.
            // Compute flock current age once; rules are based on current flock age.
            $ageDays = $capacityService->flockAgeDays($flock);

            // Determine which houses are affected on the destination side (to_house_id)
            $affectedToHouseIds = [];
            foreach ($lines as $line) {
                if (!empty($line['to_house_id'])) {
                    $affectedToHouseIds[(int) $line['to_house_id']] = true;
                }
            }
            $affectedToHouseIds = array_keys($affectedToHouseIds);

            foreach ($affectedToHouseIds as $toHouseId) {
                $house = PoultryHouse::find($toHouseId);
                if (!$house) {
                    continue;
                }

                $currentOcc = $capacityService->currentOccupancyForHouse((int) $farm->id, (int) $toHouseId);

                // delta for this transfer for this destination house
                $delta = 0;
                foreach ($lines as $line) {
                    if (!empty($line['to_house_id']) && (int) $line['to_house_id'] === (int) $toHouseId) {
                        $delta += (int) $line['quantity'];
                    }
                    // if this transfer also moves birds out of the same house, subtract them
                    if (!empty($line['from_house_id']) && (int) $line['from_house_id'] === (int) $toHouseId) {
                        $delta -= (int) $line['quantity'];
                    }
                }

                $cap = $capacityService->capacityForHouseAtAge($house, $ageDays);
                $capRule = $capacityService->capacityRuleForHouseAtAge($house, $ageDays);
                $band = $capacityService->formatAgeBand($capRule);
                $nextOcc = $currentOcc + $delta;

                if ($nextOcc > $cap) {
                    $houseName = $house->name ?? ('House #' . $toHouseId);
                    $matchText = $capRule
                        ? (" (Matched capacity band: {$band})")
                        : (" (No capacity band matched; using default capacity)");
                    throw ValidationException::withMessages([
                        'lines' => [
                            "House capacity exceeded for this flock age. " .
                            "House: {$houseName}, Age: {$ageDays} days{$matchText}" .
                            ", Allowed: {$cap}, Attempted occupancy: {$nextOcc}."
                        ],
                    ]);
                }
            }

            // Persist transfer header
            $transfer = FlockTransfer::create([
                'farm_id' => $farm->id,
                'flock_id' => $flock->id,
                'transfer_date' => $payload['transfer_date'],
                'note' => $payload['note'] ?? null,
                'created_by' => $userId,
            ]);

            // Persist lines
            foreach ($lines as $line) {
                FlockTransferLine::create([
                    'transfer_id' => $transfer->id,
                    'from_house_id' => $line['from_house_id'] ?? null,
                    'to_house_id' => $line['to_house_id'] ?? null,
                    'quantity' => (int) $line['quantity'],
                ]);
            }

            // Persist allocations (upsert each non-zero house)
            foreach ($next as $houseId => $qty) {
                if ($qty === 0) {
                    // optional: keep row at 0 or delete; we delete for cleanliness
                    FlockHouseAllocation::where('flock_id', $flock->id)
                        ->where('house_id', $houseId)
                        ->delete();
                    continue;
                }

                FlockHouseAllocation::updateOrCreate(
                    ['flock_id' => $flock->id, 'house_id' => $houseId],
                    ['farm_id' => $farm->id, 'quantity' => $qty]
                );
            }

            // Keep house status in sync with the resulting allocation state.
            // We only manage transitions between `active` and `empty` in the service.
            $affectedHouseIds = [];
            foreach ($lines as $line) {
                if (!empty($line['from_house_id'])) {
                    $affectedHouseIds[(int) $line['from_house_id']] = true;
                }
                if (!empty($line['to_house_id'])) {
                    $affectedHouseIds[(int) $line['to_house_id']] = true;
                }
            }

            if (count($affectedHouseIds) === 0 && !empty($flock->house_id)) {
                $affectedHouseIds[(int) $flock->house_id] = true;
            }

            foreach (array_keys($affectedHouseIds) as $houseId) {
                app(HouseStatusService::class)->recalculateForHouse((int) $farm->id, (int) $houseId);
            }

            return $transfer->load(['lines.fromHouse', 'lines.toHouse', 'createdBy']);
        });
    }
}

