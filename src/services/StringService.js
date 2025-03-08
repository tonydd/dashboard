export default class StringService {
    static capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    static capitalizeFirstLetterArray(arr) {
        let out = '';
        arr.forEach((word) => {
            if (typeof word !== 'string') {
                out += word + ' ';
            } else {
                out += StringService.capitalizeFirstLetter(word) + ' ';
            }
        });
        return out;
    }

    static generateRandomString(length) {
        let result = '';
        const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        const charactersLength = characters.length;
        let counter = 0;
        while (counter < length) {
            result += characters.charAt(Math.floor(Math.random() * charactersLength));
            counter += 1;
        }
        return result;
    }
}