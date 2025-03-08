export default class LogService {
    static logDev(...args) {
        if (import.meta.env.DEV) {
            console.log(...args);
        }
    }
}