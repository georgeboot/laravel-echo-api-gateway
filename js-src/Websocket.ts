import { AxiosResponse } from "axios";
import { Channel } from "./Channel";

export type Options = { authEndpoint: string, host: string, bearerToken: string, auth: any, debug: boolean };

export type MessageBody = { event: string, channel?: string, data: object };

const LOG_PREFIX = '[LE-AG-Websocket]';

export class Websocket {
    buffer: Array<object> = [];

    options: Options;

    websocket: WebSocket;

    private listeners: { [channelName: string]: { [eventName: string]: Function } } = {};

    private internalListeners: { [eventName: string]: Function } = {};

    private channelBacklog = [];

    private socketId: string;

    private closing = false;
    private hasConnected = false;

    private pingInterval: NodeJS.Timeout;

    constructor(options: Options) {
        this.options = options;

        this.connect(this.options.host);

        return this;
    }

    private connect(host: string): void {

        if (!host) {
            this.options.debug && console.error(LOG_PREFIX + `Cannont connect without host !`);

            return;
        }

        this.options.debug && console.log(LOG_PREFIX + `Trying to connect to ${host}...` );

        this.websocket = new WebSocket(host);

        this.websocket.onerror = () => {

            if (!this.hasConnected) {

                setTimeout(() => {
                    this.socketId = undefined;
                    this.connect(host);
                }, 3000);
            }
        };

        this.websocket.onopen = () => {
            this.options.debug && console.log(LOG_PREFIX + ' Connected !');
            this.hasConnected = true;

            this.send({
                event: 'whoami',
            });

            while (this.buffer.length) {
                const message = this.buffer[0];

                this.send(message);

                this.buffer.splice(0, 1);
            }

            // Register events only once connected, or they won't be registered if connection failed/lost

            this.websocket.onmessage = (messageEvent: MessageEvent) => {
                const message = this.parseMessage(messageEvent.data);
                this.options.debug && console.log(LOG_PREFIX + ' onmessage', messageEvent.data);

                if (!message) {
                    return;
                }

                if (message.channel) {
                    this.options.debug && console.log(`${LOG_PREFIX} Received event ${message.event} on channel ${message.channel}`);

                    if (this.listeners[message.channel] && this.listeners[message.channel][message.event]) {
                        this.listeners[message.channel][message.event](message.data);
                    }

                    return;
                }

                if (this.internalListeners[message.event]) {
                    this.internalListeners[message.event](message.data);
                }
            }


            // send ping every 60 seconds to keep connection alive
            this.pingInterval = setInterval(() => {
                if (this.websocket.readyState === this.websocket.OPEN) {
                    this.options.debug && console.log(LOG_PREFIX + ' Sending ping');

                    this.send({
                        event: 'ping',
                    });
                }
            }, 60 * 1000);
        }


        this.websocket.onclose = () => {
            this.options.debug && console.info('Connection closed.');

            if (this.closing){
                return;
            }

            this.hasConnected = false;
            this.options.debug && console.info('Connection lost, reconnecting...');

            setTimeout(() => {
                this.socketId = undefined;
                this.connect(host);
            }, 1000);
        };

        this.on('whoami', ({ socket_id: socketId }) => {
            this.socketId = socketId;

            this.options.debug && console.log(`${LOG_PREFIX} Just set socketId to ${socketId}`);

            // Handle the backlog and don't empty it, we'll need it if we lose connection
            let channel: Channel;

            for(channel of this.channelBacklog){
                this.actuallySubscribe(channel);
            }
        });
    }

    protected parseMessage(body: string): MessageBody {
        try {
            return JSON.parse(body);
        } catch (error) {
            this.options.debug && console.error(error);

            return undefined;
        }
    }

    getSocketId(): string {
        return this.socketId;
    }

    private socketIsReady(): boolean {
        return this.websocket.readyState === this.websocket.OPEN;
    }

    send(message: object): void {
        if (this.socketIsReady()) {
            this.websocket.send(JSON.stringify(message));
            return;
        }

        this.buffer.push(message);
    }

    close(): void {
        this.closing = true;
        this.internalListeners = {};

        clearInterval(this.pingInterval);
        this.pingInterval = undefined;

        this.websocket.close();
    }

    subscribe(channel: Channel): void {
        if (this.getSocketId()) {
            this.actuallySubscribe(channel);
        } else {
            this.options.debug && console.log(`${LOG_PREFIX} subscribe - push channel backlog for channel ${channel.name}`);

            this.channelBacklog.push(channel);
        }
    }

    private actuallySubscribe(channel: Channel): void {
        if (channel.name.startsWith('private-') || channel.name.startsWith('presence-')) {
            this.options.debug && console.log(`${LOG_PREFIX} Sending auth request for channel ${channel.name}`);

            if (this.options.bearerToken) {
                this.options.auth.headers['Authorization'] = 'Bearer ' + this.options.bearerToken;
            }

            axios.post(this.options.authEndpoint, {
                socket_id: this.getSocketId(),
                channel_name: channel.name,
            }, {
              headers: this.options.auth.headers || {}
            }).then((response: AxiosResponse) => {
                this.options.debug && console.log(`${LOG_PREFIX} Subscribing to private channel ${channel.name}`);

                this.send({
                    event: 'subscribe',
                    data: {
                        channel: channel.name,
                        ...response.data
                    },
                });
            }).catch((error) => {
                this.options.debug && console.log(`${LOG_PREFIX} Auth request for channel ${channel.name} failed`);
                this.options.debug && console.error(error);
            })
        } else {
            this.options.debug && console.log(`${LOG_PREFIX} Subscribing to channel ${channel.name}`);

            this.send({
                event: 'subscribe',
                data: {
                    channel: channel.name,
                },
            });
        }
    }

    unsubscribe(channel: Channel): void {
        this.options.debug && console.log(`${LOG_PREFIX} unsubscribe for channel ${channel.name}`);

        this.send({
            event: 'unsubscribe',
            data: {
                channel: channel.name,
            },
        });

        if (this.listeners[channel.name]) {
            delete this.listeners[channel.name];
        }
    }

    on(event: string, callback: Function = null): void {
        this.options.debug && console.log(`${LOG_PREFIX} on event ${event} ...`);

        this.internalListeners[event] = callback;
    }

    bind(channel: Channel, event: string, callback: Function): void {
        this.options.debug && console.log(`${LOG_PREFIX} bind event ${event} for channel ${channel.name} ...`);

        if (!this.listeners[channel.name]) {
            this.listeners[channel.name] = {};
        }

        this.listeners[channel.name][event] = callback;
    }

    unbindEvent(channel: Channel, event: string, callback: Function = null): void {
        this.options.debug && console.log(`${LOG_PREFIX} unbind event ${event} for channel ${channel.name} ...`);

        if (this.internalListeners[event] && (callback === null || this.internalListeners[event] === callback)) {
            delete this.internalListeners[event];
        }
    }
}
