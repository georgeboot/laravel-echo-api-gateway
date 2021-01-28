import {Channel} from "./Channel";
import {AxiosResponse} from "axios";

export type Options = { authEndpoint: string, host: string };
export type MessageBody = { event: string, channel?: string, data: object };

export class Websocket {
    buffer: Array<object> = [];

    options: Options;

    websocket: WebSocket;

    private listeners: { [channelName: string]: { [eventName: string]: Function } } = {}

    private internalListeners: { [eventName: string]: Function } = {};

    private channelBacklog = [];

    private socketId: string;

    private pingInterval: NodeJS.Timeout;

    constructor(options: Options) {
        this.options = options;

        this.websocket = new WebSocket(options.host)

        this.websocket.onopen = () => {
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
                console.log(`Received event ${message.event} on channel ${message.channel}`)

                if (this.listeners[message.channel] && this.listeners[message.channel][message.event]) {
                    this.listeners[message.channel][message.event](message.data)
                }

                return
            }

            if (this.internalListeners[message.event]) {
                this.internalListeners[message.event](message.data)
            }
        }

        this.on('whoami', ({ socket_id: socketId }) => {
            this.socketId = socketId

            console.log(`just set socketId to ${socketId}`)

            while (this.channelBacklog.length) {
                const channel = this.channelBacklog[0]

                this.actuallySubscribe(channel)

                this.channelBacklog.splice(0, 1)
            }
        })

        this.send({
            event: 'whoami',
        })

        // send ping every 60 seconds to keep connection alive
        this.pingInterval = setInterval(() => {
            console.log('Sending ping')

            this.send({
                event: 'ping',
            })
        }, 60 * 1000)

        return this
    }

    protected parseMessage(body: string): MessageBody {
        try {
            return JSON.parse(body)
        } catch (error) {
            console.error(error)

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

    close (): void {
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
            console.log(`Sending auth request for channel ${channel.name}`)

            axios.post(this.options.authEndpoint, {
                socket_id: this.getSocketId(),
                channel_name: channel.name,
            }).then((response: AxiosResponse) => {
                console.log(`Subscribing to channels ${channel.name}`)

                this.send({
                    event: 'subscribe',
                    data: {
                        channel: channel.name,
                        auth: response.data.auth,
                    },
                })
            }).catch((error) => {
                console.log(`Auth request for channel ${channel.name} failed`)
                console.error(error)
            })
        } else {
            console.log(`Subscribing to channels ${channel.name}`)

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
