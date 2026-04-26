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

    static getIntervalConfig(name, defaultValue = 60000) { // 60 secondes par défaut
        const value = import.meta.env[`${ConfigService.VITE_PREFIX}${name}`];
        if (!value) {
            console.warn(`Config interval ${name} not found, using default: ${defaultValue}ms`);
            return defaultValue;
        }

        const explode = value.split(' ');
        if (explode.length !== 2) {
            console.warn(`Config interval ${name} has invalid format, using default: ${defaultValue}ms`);
            return defaultValue;
        }

        const interval = DateService.getDeferInterval(explode[0], explode[1]);
        return interval || defaultValue;
    }
}
