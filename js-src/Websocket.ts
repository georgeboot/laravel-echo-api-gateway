import type { Channel } from "./Channel";

export type ChannelAuthorizationCallback = (error: unknown, data: AuthorizationData | null) => void;

export type ChannelAuthorization = {
    endpoint?: string,
    headers?: Record<string, string>,
    customHandler?: (params: { socketId: string, channelName: string }, callback: ChannelAuthorizationCallback) => void,
};

export type Options = {
    host?: string,
    authEndpoint?: string,
    auth?: { headers: Record<string, string> },
    channelAuthorization?: ChannelAuthorization,
    bearerToken?: string | null,
    csrfToken?: string | null,
    namespace?: string | false,
    debug?: boolean,
    /** Milliseconds between keep-alive pings. API Gateway closes idle connections after 10 minutes. */
    pingInterval?: number,
    /** Milliseconds to wait before the first reconnect attempt. Later attempts back off exponentially. */
    reconnectDelay?: number,
    /** Upper bound for the reconnect delay, in milliseconds. */
    maxReconnectDelay?: number,
    [key: string]: any,
};

export type MessageBody = { event: string, channel?: string, data?: any };

export type AuthorizationData = { auth: string, channel_data?: string };

export type AuthorizationError = { type: 'AuthError', status: number | null, error: unknown };

const LOG_PREFIX = '[LE-AG-Websocket]';

const OPEN = 1;

export class Websocket {
    options: Options;

    websocket: WebSocket | undefined;

    private buffer: Array<MessageBody> = [];

    /**
     * Every channel that should be subscribed, re-subscribed each time a new connection is identified.
     */
    private channels: Map<string, Channel> = new Map();

    private listeners: { [channelName: string]: { [eventName: string]: Function[] } } = {};

    private socketId: string | undefined;

    private closing = false;

    private reconnectAttempts = 0;

    private reconnectTimer: ReturnType<typeof setTimeout> | undefined;

    private pingTimer: ReturnType<typeof setInterval> | undefined;

    constructor(options: Options) {
        this.options = options;

        if (this.options.host) {
            this.connect();
        }
    }

    private connect(): void {
        const host = this.options.host;

        this.debug(`Trying to connect to ${host}...`);

        const websocket = new WebSocket(host);
        this.websocket = websocket;

        websocket.onopen = () => {
            this.debug('Connected !');
            this.reconnectAttempts = 0;

            this.send({ event: 'whoami' });

            while (this.buffer.length) {
                this.send(this.buffer.shift());
            }

            this.startPing();
        };

        websocket.onmessage = (messageEvent: MessageEvent) => {
            this.handleMessage(messageEvent.data);
        };

        websocket.onerror = () => {
            // A close event always follows an error, so reconnecting is handled in onclose.
            this.debug('Connection error.');
        };

        websocket.onclose = () => {
            if (this.websocket !== websocket) {
                return;
            }

            this.debug('Connection closed.');

            this.stopPing();
            this.socketId = undefined;

            if (!this.closing) {
                this.scheduleReconnect();
            }
        };
    }

    private scheduleReconnect(): void {
        const baseDelay = this.options.reconnectDelay ?? 1000;
        const maxDelay = this.options.maxReconnectDelay ?? 30000;
        const delay = Math.min(baseDelay * 2 ** this.reconnectAttempts, maxDelay);

        this.reconnectAttempts++;
        this.debug(`Connection lost, reconnecting in ${delay}ms...`);

        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = setTimeout(() => this.connect(), delay);
    }

    private startPing(): void {
        this.stopPing();

        this.pingTimer = setInterval(() => {
            if (this.socketIsReady()) {
                this.debug('Sending ping');
                this.send({ event: 'ping' });
            }
        }, this.options.pingInterval ?? 60 * 1000);
    }

    private stopPing(): void {
        clearInterval(this.pingTimer);
        this.pingTimer = undefined;
    }

    private handleMessage(body: string): void {
        const message = this.parseMessage(body);

        if (!message) {
            return;
        }

        this.debug('onmessage', body);

        if (message.channel) {
            this.dispatch(message.channel, message.event, message.data);

            return;
        }

        if (message.event === 'whoami') {
            this.socketId = message.data?.socket_id;
            this.debug(`Just set socketId to ${this.socketId}`);

            this.channels.forEach((channel) => this.actuallySubscribe(channel));
        }
    }

    private dispatch(channelName: string, event: string, data: unknown): void {
        const callbacks = this.listeners[channelName]?.[event] ?? [];

        callbacks.slice().forEach((callback) => callback(data));
    }

    protected parseMessage(body: string): MessageBody | undefined {
        try {
            return JSON.parse(body);
        } catch (error) {
            this.options.debug && console.error(error);

            return undefined;
        }
    }

    getSocketId(): string | undefined {
        return this.socketId;
    }

    private socketIsReady(): boolean {
        return this.websocket?.readyState === OPEN;
    }

    send(message: MessageBody): void {
        if (this.socketIsReady()) {
            this.websocket.send(JSON.stringify(message));

            return;
        }

        this.buffer.push(message);
    }

    close(): void {
        this.closing = true;

        clearTimeout(this.reconnectTimer);
        this.stopPing();

        this.websocket?.close();
    }

    subscribe(channel: Channel): void {
        this.channels.set(channel.name, channel);

        if (this.socketId && this.socketIsReady()) {
            this.actuallySubscribe(channel);
        } else {
            this.debug(`Channel ${channel.name} will be subscribed once connected`);
        }
    }

    private actuallySubscribe(channel: Channel): void {
        if (!channel.name.startsWith('private-') && !channel.name.startsWith('presence-')) {
            this.debug(`Subscribing to channel ${channel.name}`);
            this.send({ event: 'subscribe', data: { channel: channel.name } });

            return;
        }

        const socketId = this.socketId;

        this.debug(`Sending auth request for channel ${channel.name}`);

        this.authorize(channel.name, socketId).then((data) => {
            // Skip outdated authorizations: the connection changed or the channel was left meanwhile.
            if (socketId !== this.socketId || this.channels.get(channel.name) !== channel) {
                return;
            }

            this.debug(`Subscribing to private channel ${channel.name}`);
            this.send({ event: 'subscribe', data: { channel: channel.name, ...data } });
        }).catch((error: AuthorizationError) => {
            this.debug(`Auth request for channel ${channel.name} failed`, error);
            this.dispatch(channel.name, 'error', error);
        });
    }

    private authorize(channelName: string, socketId: string): Promise<AuthorizationData> {
        const channelAuthorization = this.options.channelAuthorization ?? {};

        if (typeof channelAuthorization.customHandler === 'function') {
            return new Promise((resolve, reject) => {
                channelAuthorization.customHandler({ socketId, channelName }, (error, data) => {
                    if (error) {
                        reject(this.authorizationError(error, (error as any)?.status ?? null));
                    } else {
                        resolve(data);
                    }
                });
            });
        }

        return fetch(channelAuthorization.endpoint ?? this.options.authEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...this.options.auth?.headers,
                ...channelAuthorization.headers,
            },
            body: JSON.stringify({
                socket_id: socketId,
                channel_name: channelName,
            }),
        }).then((response) => {
            if (!response.ok) {
                throw this.authorizationError(new Error(`Auth request failed: ${response.status}`), response.status);
            }

            return response.json();
        }, (error) => {
            throw this.authorizationError(error, null);
        });
    }

    private authorizationError(error: unknown, status: number | null): AuthorizationError {
        return { type: 'AuthError', status, error };
    }

    unsubscribe(channel: Channel): void {
        this.debug(`unsubscribe for channel ${channel.name}`);

        if (this.channels.get(channel.name) === channel) {
            this.channels.delete(channel.name);
            delete this.listeners[channel.name];
        }

        if (this.socketId && this.socketIsReady()) {
            this.send({ event: 'unsubscribe', data: { channel: channel.name } });
        }
    }

    bind(channel: Channel, event: string, callback: Function): void {
        this.debug(`bind event ${event} for channel ${channel.name} ...`);

        this.listeners[channel.name] ??= {};
        this.listeners[channel.name][event] ??= [];
        this.listeners[channel.name][event].push(callback);
    }

    unbind(channel: Channel, event: string, callback?: Function): void {
        this.debug(`unbind event ${event} for channel ${channel.name} ...`);

        const callbacks = this.listeners[channel.name]?.[event];

        if (!callbacks) {
            return;
        }

        if (callback) {
            this.listeners[channel.name][event] = callbacks.filter((existing) => existing !== callback);
        } else {
            delete this.listeners[channel.name][event];
        }
    }

    private debug(...messages: unknown[]): void {
        this.options.debug && console.log(LOG_PREFIX, ...messages);
    }
}
