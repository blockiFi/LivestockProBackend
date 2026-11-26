<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\FarmShare;
use App\Models\FeedComponent;
use App\Models\FeedComposition;
use App\Models\FeedingSchedule;
use App\Models\FeedingScheduleItem;
use App\Models\PoultryFeedProduct;
use App\Models\PoultryFeedType;
use App\Models\PoultryMedication;
use App\Models\PoultryVaccine;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use App\Models\ScheduleItemAdministrationMethod;
use App\Models\User;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationType;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies a farm's own schedule templates and feed formulations into other
 * farms. A share is a copy: later edits on either farm do not affect the other.
 */
class FarmShareService
{
    public const KIND_SCHEDULE = 'schedule';
    public const KIND_FEEDING_SCHEDULE = 'feeding_schedule';
    public const KIND_FEED_PRODUCT = 'feed_product';

    public const KINDS = [self::KIND_SCHEDULE, self::KIND_FEEDING_SCHEDULE, self::KIND_FEED_PRODUCT];

    /** Permission needed on the source farm to share. */
    public function sharePermissions(string $kind): array
    {
        return $kind === self::KIND_FEED_PRODUCT
            ? ['share feed products']
            : ['share schedules', 'manage schedules'];
    }

    /** Permissions that let a user receive a copy on the target farm (any one). */
    public function receivePermissions(string $kind): array
    {
        return $kind === self::KIND_FEED_PRODUCT
            ? ['create feed products', 'manage feed inventory', 'manage inventory']
            : ['create schedules', 'manage schedules'];
    }

    /**
     * Whether the user holds any of the given permissions on this farm, through
     * a role or a direct grant scoped to the farm. Queried directly because
     * User::roles() is not limited to the current farm, so the usual
     * hasPermissionTo() also counts roles the user holds on other farms.
     */
    public function userCan(User $user, int $farmId, array $permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        $viaRole = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.model_type', User::class)
            ->where('mr.model_id', $user->id)
            ->where('mr.farm_id', $farmId)
            ->where(fn ($q) => $q->where('r.farm_id', $farmId)->orWhereNull('r.farm_id'))
            ->whereIn('p.name', $permissions)
            ->exists();

        if ($viaRole) {
            return true;
        }

        return DB::table('model_has_permissions as mp')
            ->join('permissions as p', 'p.id', '=', 'mp.permission_id')
            ->where('mp.model_type', User::class)
            ->where('mp.model_id', $user->id)
            ->where('mp.farm_id', $farmId)
            ->whereIn('p.name', $permissions)
            ->exists();
    }

    /**
     * The user's other active farms where they may receive this kind of item.
     *
     * @return Collection<int, Farm>
     */
    public function targetFarms(User $user, Farm $source, string $kind): Collection
    {
        return $user->farms()
            ->where('farms.id', '!=', $source->id)
            ->where('farms.status', true)
            ->orderBy('farms.name')
            ->get(['farms.id', 'farms.name'])
            ->filter(fn (Farm $farm) => $this->userCan($user, $farm->id, $this->receivePermissions($kind)))
            ->values();
    }

    // ── Sending to farms the user is not a member of ─────────────────

    /** The farm's share code, created the first time it is asked for. */
    public function shareCodeFor(Farm $farm): string
    {
        if ($farm->share_code) {
            return $farm->share_code;
        }

        do {
            // No 0/O/1/I so codes read aloud cleanly.
            $code = collect(range(1, 8))
                ->map(fn () => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)])
                ->implode('');
        } while (Farm::where('share_code', $code)->exists());

        $farm->forceFill(['share_code' => $code])->save();

        return $code;
    }

    public function findFarmByCode(string $code): ?Farm
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        return $code === '' ? null : Farm::where('share_code', $code)->where('status', true)->first();
    }

    /** Record a pending share for the target farm and tell its managers. */
    public function send(Model $item, string $kind, Farm $source, Farm $target, User $user, ?string $message = null): FarmShare
    {
        $share = FarmShare::create([
            'kind' => $kind,
            'source_farm_id' => $source->id,
            'source_item_id' => $item->id,
            'item_name' => $item->name ?? $item->title,
            'item_subtype' => $item instanceof Schedule ? $item->schedule_type : null,
            'item_count' => $item instanceof PoultryFeedProduct ? $item->compositions->count() : $item->items->count(),
            'target_farm_id' => $target->id,
            'shared_by' => $user->id,
            'message' => $message,
            'status' => FarmShare::STATUS_PENDING,
        ]);

        $this->notify($share, $target->id, $this->receivePermissions($kind),
            "{$source->name} shared a {$this->kindLabel($share)} with you",
            "\"{$share->item_name}\" is waiting in Shared with this farm. Accept it to add a copy, or decline it.",
            '/dashboard/poultry/shared'
        );

        return $share;
    }

    /** Accept a pending share: copy the item into the target farm. */
    public function accept(FarmShare $share, User $user): Model
    {
        $source = Farm::findOrFail($share->source_farm_id);
        $target = Farm::findOrFail($share->target_farm_id);
        $item = $this->findSource($source, $share->kind, (int) $share->source_item_id);
        if (! $item) {
            abort(410, 'The sending farm has removed this item, so it can no longer be accepted.');
        }

        $copy = DB::transaction(function () use ($share, $item, $source, $target, $user) {
            $locked = FarmShare::whereKey($share->id)->lockForUpdate()->first();
            if ($locked->status !== FarmShare::STATUS_PENDING) {
                abort(409, 'This share has already been answered.');
            }
            $copy = $this->copyTo($item, $source, $target, $user);
            $locked->update([
                'status' => FarmShare::STATUS_ACCEPTED,
                'responded_by' => $user->id,
                'responded_at' => now(),
                'accepted_item_id' => $copy->id,
            ]);

            return $copy;
        });

        $this->notifySender($share->fresh(), "{$target->name} accepted your {$this->kindLabel($share)}");

        return $copy;
    }

    public function decline(FarmShare $share, User $user): FarmShare
    {
        $share->update([
            'status' => FarmShare::STATUS_DECLINED,
            'responded_by' => $user->id,
            'responded_at' => now(),
        ]);
        $this->notifySender($share, "{$share->targetFarm->name} declined your {$this->kindLabel($share)}");

        return $share;
    }

    public function kindLabel(FarmShare $share): string
    {
        return match ($share->kind) {
            self::KIND_SCHEDULE => ($share->item_subtype ?: 'health').' schedule',
            self::KIND_FEEDING_SCHEDULE => 'feeding schedule',
            default => 'feed formulation',
        };
    }

    private function notifySender(FarmShare $share, string $title): void
    {
        if (! $share->shared_by) {
            return;
        }
        try {
            app(NotificationService::class)->send(
                NotificationMessage::make(NotificationType::FARM_ACTIVITY_ALERT)
                    ->farm($share->source_farm_id)
                    ->to($share->shared_by)
                    ->title($title)
                    ->body("\"{$share->item_name}\"")
                    ->action('/dashboard/poultry/shared', 'View shares')
                    ->dedupe('farm_share_answer:'.$share->id)
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notify(FarmShare $share, int $farmId, array $permissions, string $title, string $body, string $url): void
    {
        try {
            app(NotificationService::class)->send(
                NotificationMessage::make(NotificationType::FARM_ACTIVITY_ALERT)
                    ->farm($farmId)
                    ->toFarmMembersWithPermission(...$permissions)
                    ->title($title)
                    ->body($body)
                    ->priority('normal')
                    ->action($url, 'Review')
                    ->dedupe('farm_share:'.$share->id)
            );
        } catch (\Throwable $e) {
            // A notification failure must never undo the share itself.
            report($e);
        }
    }

    /** Load the item to share, scoped to the source farm's own (non-default) records. */
    public function findSource(Farm $source, string $kind, int $id): ?Model
    {
        return match ($kind) {
            self::KIND_SCHEDULE => Schedule::with(['items.administrationMethods'])
                ->where('id', $id)->where('farm_id', $source->id)->where('type', '!=', 'default')->first(),
            self::KIND_FEEDING_SCHEDULE => FeedingSchedule::with(['items'])
                ->where('id', $id)->where('farm_id', $source->id)->first(),
            self::KIND_FEED_PRODUCT => PoultryFeedProduct::with(['compositions.component'])
                ->where('id', $id)->where('farm_id', $source->id)->first(),
            default => null,
        };
    }

    /** Copy an item into one target farm and return the new record. */
    public function copyTo(Model $item, Farm $source, Farm $target, User $user): Model
    {
        return DB::transaction(fn () => match (true) {
            $item instanceof Schedule => $this->copySchedule($item, $source, $target, $user),
            $item instanceof FeedingSchedule => $this->copyFeedingSchedule($item, $source, $target),
            $item instanceof PoultryFeedProduct => $this->copyFeedProduct($item, $source, $target, $user),
        });
    }

    private function copySchedule(Schedule $schedule, Farm $source, Farm $target, User $user): Schedule
    {
        $copy = Schedule::create([
            'schedule_type' => $schedule->schedule_type,
            'poultry_type_id' => $schedule->poultry_type_id,
            'type' => 'user',
            'farm_id' => $target->id,
            'name' => $this->uniqueName(
                Schedule::where('farm_id', $target->id)->where('schedule_type', $schedule->schedule_type),
                'name',
                $schedule->name,
                $source
            ),
            'description' => $schedule->description,
        ]);

        foreach ($schedule->items as $item) {
            $newItem = ScheduleItem::create([
                'schedule_id' => $copy->id,
                'age_days' => $item->age_days,
                'is_recurring' => $item->is_recurring,
                'interval_days' => $item->interval_days,
                'poultry_vaccine_id' => $this->mapVaccine($item->poultry_vaccine_id, $target),
                'poultry_medication_id' => $this->mapMedication($item->poultry_medication_id, $target),
                'name' => $item->name,
                'dose_unit' => $item->dose_unit,
                'dose' => $item->dose,
                'withdrawal_period_days' => $item->withdrawal_period_days,
                'storage_instructions' => $item->storage_instructions,
                'description' => $item->description,
            ]);

            foreach ($item->administrationMethods as $method) {
                ScheduleItemAdministrationMethod::create([
                    'schedule_item_id' => $newItem->id,
                    'administration_method_id' => $method->administration_method_id,
                    'is_preferred' => $method->is_preferred,
                ]);
            }
        }

        return $copy->load('items');
    }

    private function copyFeedingSchedule(FeedingSchedule $schedule, Farm $source, Farm $target): FeedingSchedule
    {
        $copy = FeedingSchedule::create([
            'title' => $this->uniqueName(
                FeedingSchedule::where('farm_id', $target->id),
                'title',
                $schedule->title,
                $source
            ),
            'description' => $schedule->description,
            'start_date' => $schedule->start_date,
            'end_date' => $schedule->end_date,
            'type' => 'user',
            'farm_id' => $target->id,
            'poultry_type_id' => $schedule->poultry_type_id,
        ]);

        foreach ($schedule->items as $item) {
            FeedingScheduleItem::create([
                'feeding_schedule_id' => $copy->id,
                'feed_type_id' => $this->mapFeedType($item->feed_type_id, $target),
                'feeding_times' => $item->feeding_times,
                'quantity' => $item->quantity,
                'feeding_day' => $item->feeding_day,
                'start_day' => $item->start_day,
                'end_day' => $item->end_day,
            ]);
        }

        return $copy->load('items');
    }

    private function copyFeedProduct(PoultryFeedProduct $product, Farm $source, Farm $target, User $user): PoultryFeedProduct
    {
        $copy = PoultryFeedProduct::create([
            'name' => $this->uniqueName(
                PoultryFeedProduct::where('farm_id', $target->id),
                'name',
                $product->name,
                $source
            ),
            'description' => $product->description,
            'crude_protein' => $product->crude_protein,
            'crude_fat' => $product->crude_fat,
            'crude_fiber' => $product->crude_fiber,
            'calcium' => $product->calcium,
            'phosphorus' => $product->phosphorus,
            'metabolizable_energy' => $product->metabolizable_energy,
            'moisture' => $product->moisture,
            'ash' => $product->ash,
            'unit' => $product->unit,
            'price' => $product->price,
            'poultry_feed_type_id' => $this->mapFeedType($product->poultry_feed_type_id, $target),
            'farm_id' => $target->id,
            'created_by' => $user->id,
            'status' => $product->status ?? 'active',
        ]);

        foreach ($product->compositions as $composition) {
            $componentId = $this->mapComponent($composition->component, $target, $user);
            if (! $componentId) {
                continue;
            }
            FeedComposition::create([
                'poultry_feed_product_id' => $copy->id,
                'feed_component_id' => $componentId,
                'percentage' => $composition->percentage,
            ]);
        }

        return $copy->load(['feedType', 'compositions.component']);
    }

    /**
     * Keep the name unless the target already has one like it, then add the
     * source farm's name so the two can be told apart.
     */
    private function uniqueName($query, string $column, string $name, Farm $source): string
    {
        if (! (clone $query)->where($column, $name)->exists()) {
            return $name;
        }

        $candidate = "{$name} (from {$source->name})";
        $n = 2;
        while ((clone $query)->where($column, $candidate)->exists()) {
            $candidate = "{$name} (from {$source->name} {$n})";
            $n++;
        }

        return $candidate;
    }

    /** Records with no farm, or already on the target farm, are usable as they are. */
    private function usableOn(?Model $record, Farm $target): bool
    {
        return $record && ($record->farm_id === null || (int) $record->farm_id === $target->id || $record->type === 'default');
    }

    private function mapMedication(?int $id, Farm $target): ?int
    {
        if (! $id) {
            return null;
        }
        $medication = PoultryMedication::find($id);
        if (! $medication || $this->usableOn($medication, $target)) {
            return $id;
        }

        $match = PoultryMedication::where('name', $medication->name)
            ->where(fn ($q) => $q->where('farm_id', $target->id)->orWhereNull('farm_id')->orWhere('type', 'default'))
            ->first();

        return $match?->id ?? PoultryMedication::create([
            'farm_id' => $target->id,
            'type' => 'user',
            'name' => $medication->name,
            'description' => $medication->description,
        ])->id;
    }

    private function mapVaccine(?int $id, Farm $target): ?int
    {
        if (! $id) {
            return null;
        }
        $vaccine = PoultryVaccine::find($id);
        if (! $vaccine || $this->usableOn($vaccine, $target)) {
            return $id;
        }

        // Vaccine names are unique across the platform, so a farm's own vaccine
        // is already the shared catalogue entry for that name: keep pointing at it.
        return $id;
    }

    private function mapFeedType(?int $id, Farm $target): ?int
    {
        if (! $id) {
            return null;
        }
        $feedType = PoultryFeedType::find($id);
        if (! $feedType || $this->usableOn($feedType, $target)) {
            return $id;
        }

        $match = PoultryFeedType::where('name', $feedType->name)
            ->where('poultry_type_id', $feedType->poultry_type_id)
            ->where(fn ($q) => $q->where('farm_id', $target->id)->orWhereNull('farm_id'))
            ->first();

        // Feed type names are unique per poultry type across the platform, so
        // when the target has no match the source farm's type is reused.
        return $match?->id ?? $id;
    }

    private function mapComponent(?FeedComponent $component, Farm $target, User $user): ?int
    {
        if (! $component) {
            return null;
        }
        if ($this->usableOn($component, $target)) {
            return $component->id;
        }

        $match = FeedComponent::where('name', $component->name)
            ->where(fn ($q) => $q->where('farm_id', $target->id)->orWhereNull('farm_id'))
            ->first();
        if ($match) {
            return $match->id;
        }

        $trashed = FeedComponent::onlyTrashed()
            ->where('name', $component->name)->where('farm_id', $target->id)->first();
        if ($trashed) {
            $trashed->restore();

            return $trashed->id;
        }

        return FeedComponent::create([
            'farm_id' => $target->id,
            'name' => $component->name,
            'description' => $component->description,
            'unit' => $component->unit,
            'crude_protein' => $component->crude_protein,
            'crude_fat' => $component->crude_fat,
            'crude_fiber' => $component->crude_fiber,
            'calcium' => $component->calcium,
            'phosphorus' => $component->phosphorus,
            'metabolizable_energy' => $component->metabolizable_energy,
            'moisture' => $component->moisture,
            'ash' => $component->ash,
            'status' => $component->status ?? 'active',
            'created_by' => $user->id,
        ])->id;
    }
}
