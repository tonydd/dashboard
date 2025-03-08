import DateService from "@/services/DateService.js";
export default class ConfigService {
    static VITE_PREFIX = 'VITE_';


    static getBooleanConfig(name) {
        const value = ConfigService.getConfig(name);
        return (value === 'true');
    }
    static getConfig(name, defaultValue = null) {
        const value = import.meta.env[`${ConfigService.VITE_PREFIX}${name}`];
        return value || defaultValue;
    }

    static getIntervalConfig(name, defaultValue = null) {
        const value = import.meta.env[`${ConfigService.VITE_PREFIX}${name}`];
        if (!value) {
            return defaultValue;
        }

        const explode = value.split(' ');
        if (explode.length !== 2) {
            return defaultValue;
        }

        return DateService.getDeferInterval(explode[0], explode[1]) || defaultValue;
    }
}
