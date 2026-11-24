class ApiError extends Error {
    constructor(status, message) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
    }
}

let csrfToken = null;

const API = {
    get(route, params = {}, mode = 'cors') {
        return this._fetch(route, 'GET', params, null, mode);
    },

    post(route, data = {}, mode = 'cors') {
        return this._fetch(route, 'POST', null, data, mode);
    },

    put(route, data = {}, mode = 'cors') {
        return this._fetch(route, 'PUT', null, data, mode);
    },

    delete(route, mode = 'cors') {
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
            mode,
            credentials: 'include',
        };

        if (csrfToken && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            options.headers['X-CSRF-Token'] = csrfToken;
        }

        if (data) {
            options.body = JSON.stringify(data);
        }

        return fetch(url, options)
            .then(response => {
                if (!response.ok) {
                    if (response.status === 401) {
                        csrfToken = null;
                        window.dispatchEvent(new Event('dashboard:unauthorized'));
                    }

                    return response.json()
                        .catch(() => ({}))
                        .then(body => {
                            throw new ApiError(
                                response.status,
                                body.error || `API request failed with status ${response.status}`
                            );
                        });
                }

                if (response.status === 204) {
                    return null;
                }

                return response.json();
            })
            .then(body => {
                if (body?.csrfToken) {
                    csrfToken = body.csrfToken;
                }

                return body;
            })
            .catch(error => {
                if (error instanceof ApiError) {
                    throw error;
                }
                throw new Error(`API request error: ${error.message}`);
            });
    },
};

export default API;