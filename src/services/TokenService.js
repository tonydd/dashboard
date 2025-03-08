export default class TokenService {
    static isTokenValid(tokenData) {
        const now = Date.now() / 1000;
        return tokenData.hasOwnProperty('expires_at')
        && now < tokenData.expires_at;
    }

    static getExpirationDiffSeconds(tokenData) {
        const now = Date.now() / 1000;
        return Math.round(tokenData.expires_at - now);
    }
}