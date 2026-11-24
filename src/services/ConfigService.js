import DateService from "@/services/DateService.js";
export default class ConfigService {
    static VITE_PREFIX = 'VITE_';
    static API_CONFIGS = new Set([
        'API_BASE_URL',
        'FUEL_API_URL',
        'SOLAR_API_URL',
        'WATER_HEATER_API_URL',
        'WATER_QUALITY_API_URL',
        'BACKGROUND_URL',
    ]);


    static getBooleanConfig(name) {
        const value = ConfigService.getConfig(name);
        return (value === 'true');
    }
    static getConfig(name, defaultValue = null) {
        const value = import.meta.env[`${ConfigService.VITE_PREFIX}${name}`] || defaultValue;

        if (!import.meta.env.DEV || typeof value !== 'string' || !ConfigService.API_CONFIGS.has(name)) {
            return value;
        }

        try {
            const url = new URL(value, window.location.origin);
            if (!['localhost', '127.0.0.1'].includes(url.hostname)) {
                return value;
            }

            if (name === 'API_BASE_URL') {
                return '/api';
            }

            return `${url.pathname}${url.search}${url.hash}`;
        } catch {
            return value;
        }
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
