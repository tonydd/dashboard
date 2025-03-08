export default class DateService {
    static displayTimestampAsTime(timestamp, withSeconds = false) {
        const date = new Date(timestamp * 1000);
        return DateService.formatTime(date);
    }

    static formatDateTime(date) {
        return new Date(date).toLocaleString('fr-FR');
    }
    static formatDate(date) {
        return new Date(date).toLocaleDateString('fr-FR');
    }
    static formatTime(date, withSeconds = false) {
        if (withSeconds) {
            return new Date(date).toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
        } else {
            return new Date(date).toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit'});
        }
    }
    static now() {
        return Math.round(Date.now() / 1000);
    }

    static getHour(currentSecondsTimestamp) {
        const date = new Date(currentSecondsTimestamp * 1000);
        return date.getHours();
    }

    static getMinutes(currentSecondsTimestamp) {
        const date = new Date(currentSecondsTimestamp * 1000);
        return date.getMinutes();
    }

    static getMonth(currentSecondsTimestamp) {
        const date = new Date(currentSecondsTimestamp * 1000);
        return date.getMonth() + 1;
    }
    static dateFromSeconds(seconds) {
        return new Date(seconds * 1000);
    }
    static secondsToHourMinuteSecondsTwoDigits(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = seconds % 60;
        return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
    }

    static getDeferInterval(value, unit = 'seconds') {
        switch (unit) {
            case 'second':
                return value * 1000;
            case 'minute':
                return value * 60 * 1000;
            case 'hour':
                return value * 3600 * 1000;
            case 'day':
                return value * 86400 * 1000;
            default:
                return value;
        }
    }
}