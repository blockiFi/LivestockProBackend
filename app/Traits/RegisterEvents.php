<?php

namespace App\Traits;

use App\Models\PoultryEvent;
use Illuminate\Support\Facades\Auth;

trait RegisterEvents 
{
    /**
     * Register a new poultry event
     *
     * @param int $farmId The ID of the farm
     * @param int|null $flockId The ID of the flock (optional)
     * @param string $event The event description
     * @param string|null $eventType The type of event (optional)
     * @param string|null $tableName The name of the related table (optional)
     * @param int|null $tableId The ID of the related record (optional)
     * @param \DateTime|null $eventDate The date of the event (defaults to now)
     * @param int|null $performedBy The ID of the user who performed the event (defaults to authenticated user)
     * @return PoultryEvent
     */
    public function RegisterEvent(
        int $farmId,
        ?int $flockId = null,
        ?string $eventType = null,
        ?string $tableName = null,
        ?int $tableId = null
    ): PoultryEvent {
        $event = new PoultryEvent();
        $event->farm_id = $farmId;
        $event->flock_id = $flockId;
        $event->event_type = $eventType;
        $event->table_name = $tableName;
        $event->table_id = $tableId;
        $event->event_date = now();
        
        // Generate event description based on event type and related model
        if ($tableName && $tableId) {
            $modelClass = 'App\\Models\\' . ucfirst($tableName);
            if (class_exists($modelClass)) {
                $relatedModel = $modelClass::find($tableId);
                if ($relatedModel) {
                    $event->event = "{$eventType} performed on {$tableName} #{$tableId}: " . json_encode($relatedModel->toArray());
                }else{
                    $event->event = "{$eventType} performed on {$tableName} #{$tableId}: " ; 
                }
            }
        }
        if(!isset($event->event)){
            $event->event = "{$eventType} performed on {$tableName} #{$tableId}" ;  

        }
        
        $event->performed_by = $performedBy ?? Auth::id();
        $event->save();
        
        return $event;
    }
}