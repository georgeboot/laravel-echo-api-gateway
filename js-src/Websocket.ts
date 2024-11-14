import { AxiosResponse } from "axios";
import { Channel } from "./Channel";

export type Options = { authEndpoint: string, host: string, bearerToken: string, auth: any, debug: boolean };

export type MessageBody = { event: string, channel?: string, data: object };

export class Websocket {
    buffer: Array<object> = [];

    options: Options;

    websocket: WebSocket;

    private listeners: { [channelName: string]: { [eventName: string]: Function } } = {}

    private internalListeners: { [eventName: string]: Function } = {};

    private channelBacklog = [];

    private socketId: string;

    private closing = false;

    private pingInterval: NodeJS.Timeout;

    private connect(host: string): void {
        this.options.debug && console.log('Connecting');

        this.websocket = new WebSocket(host)

        this.websocket.onopen = () => {
            this.send({
                event: 'whoami',
            })

            while (this.buffer.length) {
                const message = this.buffer[0]

                this.send(message)

                this.buffer.splice(0, 1)
            }
        }

        this.websocket.onmessage = (messageEvent: MessageEvent) => {
            const message = this.parseMessage(messageEvent.data)

            if (!message) {
                return
            }

            if (message.channel) {
                this.options.debug && console.log(`Received event ${message.event} on channel ${message.channel}`)

                if (this.listeners[message.channel] && this.listeners[message.channel][message.event]) {
                    this.listeners[message.channel][message.event](message.data)
                }

                return
            }

            if (this.internalListeners[message.event]) {
                this.internalListeners[message.event](message.data)
            }

        }


        this.websocket.onclose = () => {
            if (this.socketId && !this.closing || !this.socketId) {
                this.options.debug && console.info('Connection lost, reconnecting...');
                setTimeout(() => {
                    this.socketId = undefined
                    this.connect(host)
                }, 1000);
            }
        };

        this.on('whoami', ({ socket_id: socketId }) => {
            this.socketId = socketId

            this.options.debug && console.log(`just set socketId to ${socketId}`)

            while (this.channelBacklog.length) {
                const channel = this.channelBacklog[0]

                this.actuallySubscribe(channel)

                this.channelBacklog.splice(0, 1)
            }
        })


        // send ping every 60 seconds to keep connection alive
        this.pingInterval = setInterval(() => {
            if (this.websocket.readyState === this.websocket.OPEN) {
                this.options.debug && console.log('Sending ping')
                this.send({
                    event: 'ping',
                })
            }
        }, 60 * 1000)

    }

    constructor(options: Options) {
        this.options = options;

        this.connect(this.options.host);

        return this
    }

    protected parseMessage(body: string): MessageBody {
        try {
            return JSON.parse(body)
        } catch (error) {
            this.options.debug && console.error(error)

            return undefined
        }
    }

    getSocketId(): string {
        return this.socketId
    }

    private socketIsReady(): boolean {
        return this.websocket.readyState === this.websocket.OPEN
    }

    send(message: object): void {
        if (this.socketIsReady()) {
            this.websocket.send(JSON.stringify(message))
            return
        }

        this.buffer.push(message)
    }

    close(): void {
        this.closing = true
        this.internalListeners = {}

        clearInterval(this.pingInterval)
        this.pingInterval = undefined

        this.websocket.close()
    }

    subscribe(channel: Channel): void {
        if (this.getSocketId()) {
            this.actuallySubscribe(channel)
        } else {
            this.channelBacklog.push(channel)
        }
    }

    private actuallySubscribe(channel: Channel): void {
        if (channel.name.startsWith('private-') || channel.name.startsWith('presence-')) {
            this.options.debug && console.log(`Sending auth request for channel ${channel.name}`)

            if (this.options.bearerToken) {
                this.options.auth.headers['Authorization'] = 'Bearer ' + this.options.bearerToken;
            }

            axios.post(this.options.authEndpoint, {
                socket_id: this.getSocketId(),
                channel_name: channel.name,
            }, {
              headers: this.options.auth.headers || {}
            }).then((response: AxiosResponse) => {
                this.options.debug && console.log(`Subscribing to channels ${channel.name}`)

                this.send({
                    event: 'subscribe',
                    data: {
                        channel: channel.name,
                        ...response.data
                    },
                })
            }).catch((error) => {
                this.options.debug && console.log(`Auth request for channel ${channel.name} failed`)
                this.options.debug && console.error(error)
            })
        } else {
            this.options.debug && console.log(`Subscribing to channels ${channel.name}`)

            this.send({
                event: 'subscribe',
                data: {
                    channel: channel.name,
                },
            })
        }
    }

    unsubscribe(channel: Channel): void {
        this.send({
            event: 'unsubscribe',
            data: {
                channel: channel.name,
            },
        })

        if (this.listeners[channel.name]) {
            delete this.listeners[channel.name]
        }
    }

    on(event: string, callback: Function = null): void {
        this.internalListeners[event] = callback
    }

    bind(channel: Channel, event: string, callback: Function): void {
        if (!this.listeners[channel.name]) {
            this.listeners[channel.name] = {}
        }

        this.listeners[channel.name][event] = callback
    }

    unbindEvent(channel: Channel, event: string, callback: Function = null): void {
        if (this.internalListeners[event] && (callback === null || this.internalListeners[event] === callback)) {
            delete this.internalListeners[event]
        }
    }
}
