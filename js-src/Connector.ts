import { Websocket } from "./Websocket";
import type { Options } from "./Websocket";
import { Channel } from "./Channel";

export { Channel };
export { EventFormatter } from "./EventFormatter";
export type { AuthorizationData, AuthorizationError, ChannelAuthorization, Options } from "./Websocket";

const LOG_PREFIX = '[LE-AG-Connector]';

/**
 * Laravel Echo connector for API Gateway websockets.
 *
 * It does not extend laravel-echo's internal classes, so it works with laravel-echo v1 and v2:
 * both create custom connectors with `new options.broadcaster(options)`.
 */
export class Connector {
    /**
     * Default connector options, matching laravel-echo's defaults.
     */
    static readonly _defaultOptions = {
        auth: {
            headers: {},
        },
        authEndpoint: '/broadcasting/auth',
        csrfToken: null,
        bearerToken: null,
        host: null,
        namespace: 'App.Events',
    };

    /**
     * Connector options.
     */
    options: Options;

    socket: Websocket;

    /**
     * All of the subscribed channel names.
     */
    channels: { [name: string]: Channel } = {};

    /**
     * Create a new class instance. Without a host no connection is opened, which keeps
     * laravel-echo v2's `new broadcaster()` capability check free of side effects.
     */
    constructor(options: Options = {}) {
        this.setOptions(options);
        this.connect();
    }

    /**
     * Merge the custom options with the defaults and add the CSRF and bearer token headers.
     */
    protected setOptions(options: Options): void {
        this.options = {
            ...Connector._defaultOptions,
            ...options,
            auth: {
                headers: { ...(options.auth?.headers ?? {}) },
            },
        };

        const csrfToken = this.csrfToken();

        if (csrfToken) {
            this.options.auth.headers['X-CSRF-TOKEN'] = csrfToken;
        }

        if (this.options.bearerToken) {
            this.options.auth.headers['Authorization'] = 'Bearer ' + this.options.bearerToken;
        }
    }

    /**
     * Extract the CSRF token from the page.
     */
    protected csrfToken(): string | null {
        if (typeof window !== 'undefined' && (window as any).Laravel?.csrfToken) {
            return (window as any).Laravel.csrfToken;
        }

        if (this.options.csrfToken) {
            return this.options.csrfToken;
        }

        if (typeof document !== 'undefined' && typeof document.querySelector === 'function') {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? null;
        }

        return null;
    }

    /**
     * Create a fresh websocket connection.
     */
    connect(): void {
        this.options.debug && console.log(LOG_PREFIX + ' Connect ...');

        this.socket = new Websocket(this.options);
    }

    /**
     * Listen for an event on a channel instance.
     */
    listen(name: string, event: string, callback: Function): Channel {
        return this.channel(name).listen(event, callback);
    }

    /**
     * Get a channel instance by name.
     */
    channel(name: string): Channel {
        if (!this.channels[name]) {
            this.channels[name] = new Channel(this.socket, name, this.options);
        }

        return this.channels[name];
    }

    /**
     * Get a private channel instance by name.
     */
    privateChannel(name: string): Channel {
        return this.channel('private-' + name);
    }

    /**
     * Get a presence channel instance by name.
     */
    presenceChannel(name: string): Channel {
        return this.channel('presence-' + name);
    }

    /**
     * Leave the given channel, as well as its private and presence variants.
     */
    leave(name: string): void {
        [name, 'private-' + name, 'presence-' + name].forEach((channelName) => {
            this.leaveChannel(channelName);
        });
    }

    /**
     * Leave the given channel.
     */
    leaveChannel(name: string): void {
        if (this.channels[name]) {
            this.channels[name].unsubscribe();

            delete this.channels[name];
        }
    }

    /**
     * Get the socket ID for the connection.
     */
    socketId(): string | undefined {
        return this.socket.getSocketId();
    }

    /**
     * Disconnect socket connection.
     */
    disconnect(): void {
        this.options.debug && console.log(LOG_PREFIX + ' Disconnect ...');

        this.socket.close();
    }
}

/**
 * Kept for backwards compatibility: `new Echo({ broadcaster })`. A function declaration (not an arrow
 * function) so laravel-echo can call it with `new`.
 */
export function broadcaster(options?: Options): Connector {
    return new Connector(options);
}
