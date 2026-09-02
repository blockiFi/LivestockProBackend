<?php

namespace Database\Seeders\LayerDemo;

use Carbon\Carbon;

class LayerDemoContext
{
    public const FARM_NAME = 'Sunrise Layer Farm';

    public const FLOCK_DAYS = 400;

    public const FLOCK_QUANTITY = 1000;

    public static function arrivalDate(): Carbon
    {
        return Carbon::today()->subDays(self::FLOCK_DAYS - 1);
    }

    public static function expectedEndDate(): Carbon
    {
        return self::arrivalDate()->copy()->addDays(504);
    }
}
