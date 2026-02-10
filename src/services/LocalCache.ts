import DateService from "./DateService"

export enum CacheType {
    memory,
    local,
    session
}

export class LocalCache {
    private readonly type: CacheType;
    private readonly name: string;
    private memory: Map<string, any> = new Map();

    constructor(name: string, cacheAdapter: CacheType) {
        this.type = cacheAdapter;
        this.name = name;
    }

    public get(key: string): any {
        let data: any = null;
        key = this.buildKey(key);
        switch (this.type) {
            case CacheType.memory:
                data = this.memory.get(key);
                break;
            case CacheType.local:
                data = JSON.parse(localStorage.getItem(key));
                break;
            case CacheType.session:
                data = JSON.parse(sessionStorage.getItem(key));
                break;
        }

        if (!data || Object.keys(data).length === 0) {
            return null;
        }

        if (data.expires && data.expires < DateService.now()) {
            this.unset(key);
        }

        return data.value;
    }

    public set(key: string, value: any, expires?: number) {
        key = this.buildKey(key);
        switch (this.type) {
            case CacheType.memory:
                this.memory.set(key, {value, expires});
                break;
            case CacheType.local:
                localStorage.setItem(key, JSON.stringify({value, expires}));
                break;
            case CacheType.session:
                sessionStorage.setItem(key, JSON.stringify({value, expires}));
                break;
        }
    }

    public unset(key: string): void {
        key = this.buildKey(key);
        switch (this.type) {
            case CacheType.memory:
                this.memory.delete(key);
                break;
            case CacheType.local:
                localStorage.removeItem(key);
                break;
            case CacheType.session:
                sessionStorage.removeItem(key);
                break;
        }
    }

    private buildKey(key: string): string
    {
        return `LocalCache-${this.type}-${this.name}-${key}`;
    }
}