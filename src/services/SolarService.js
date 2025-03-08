import DateService from "@/services/DateService.js";

export default class SolarService {
    static isSolarProductionPeriod(currentSecondsTimestamp) {
        const date = new Date(currentSecondsTimestamp * 1000);
        const hour = date.getHours();
        const minutes = date.getMinutes();
        const month = DateService.getMonth(currentSecondsTimestamp);

        const sunrise = SolarService.SUNRISE_SUNSET_PER_MONTH[month].sunrise;
        const sunset = SolarService.SUNRISE_SUNSET_PER_MONTH[month].sunset;

        if (hour < sunrise.hour || hour > sunset.hour) {
            return false;
        }
        if (hour === sunrise.hour && minutes < sunrise.minute) {
            return false;
        }
        if (hour === sunset.hour && minutes >= sunset.minute) {
            return false;
        }

        return true;

    }

    static SUNRISE_SUNSET_PER_MONTH = {
        1: {sunrise: {hour: 8, minute: 30}, sunset: {hour: 16, minute: 30}},
        2: {sunrise: {hour: 8, minute: 0}, sunset: {hour: 17, minute: 0}},
        3: {sunrise: {hour: 7, minute: 30}, sunset: {hour: 18, minute: 0}},
        4: {sunrise: {hour: 7, minute: 0}, sunset: {hour: 18, minute: 30}},
        5: {sunrise: {hour: 6, minute: 30}, sunset: {hour: 19, minute: 0}},
        6: {sunrise: {hour: 6, minute: 0}, sunset: {hour: 19, minute: 30}},
        7: {sunrise: {hour: 6, minute: 0}, sunset: {hour: 19, minute: 30}},
        8: {sunrise: {hour: 6, minute: 30}, sunset: {hour: 19, minute: 0}},
        9: {sunrise: {hour: 7, minute: 0}, sunset: {hour: 18, minute: 30}},
        10: {sunrise: {hour: 7, minute: 30}, sunset: {hour: 18, minute: 0}},
        11: {sunrise: {hour: 8, minute: 0}, sunset: {hour: 17, minute: 30}},
        12: {sunrise: {hour: 8, minute: 30}, sunset: {hour: 16, minute: 30}},
    };
}