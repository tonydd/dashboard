const API = {
    get(route, params = {}, mode = 'same-origin') {
        return this._fetch(route, 'GET', params, null, mode);
    },

    post(route, data = {}, mode = 'same-origin') {
        return this._fetch(route, 'POST', null, data, mode);
    },

    put(route, data = {}, mode = 'same-origin') {
        return this._fetch(route, 'PUT', null, data, mode);
    },

    delete(route, mode = 'same-origin') {
        return this._fetch(route, 'DELETE', null, null, mode);
    },

    _fetch(route, method, params, data, mode) {
        const url = new URL(route, window.location.origin);
        if (params) {
            url.search = new URLSearchParams(params).toString();
        }

        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
            },
            mode: mode
        };

        if (data) {
            options.body = JSON.stringify(data);
        }

        return fetch(url, options)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`API request failed with status ${response.status}`);
                }
                return response.json();
            })
            .catch(error => {
                throw new Error(`API request error: ${error.message}`);
            });
    },
};

export default API;