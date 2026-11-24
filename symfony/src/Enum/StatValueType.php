<?php

namespace App\Enum;

enum StatValueType: string
{
    case Fuel = 'fuel';
    case Solar = 'solar';
    case Thermor = 'thermor';
    case ThermorSchedule = 'thermor_schedule';

    case WaterQuality = 'water_quality';
    case Background = 'day_image';
}
