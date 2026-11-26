<?php

namespace App\Enum;

enum KeyValueKey: string
{
    case Fuel = 'fuel';
    case Solar = 'solar';
    case Thermor = 'thermor';
    case ThermorSchedule = 'thermor_schedule';
    case ThermorScheduleState = 'thermor_schedule_state';

    case WaterQuality = 'water_quality';
    case Background = 'day_image';
    case DashboardSlideshowReturnSeconds = 'dashboard_slideshow_return_seconds';
    case MessengerHeartbeat = 'messenger_heartbeat';
}
