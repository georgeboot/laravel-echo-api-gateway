import { EventFormatter } from 'laravel-echo/src/util';
import { Channel as BaseChannel } from 'laravel-echo/src/channel/channel';
import { PresenceChannel } from "laravel-echo/src/channel";
import { Websocket } from "./Websocket";

const LOG_PREFIX = '[LE-AG-Channel]';

/**
 * This class represents a Pusher channel.
 */
export class Channel extends BaseChannel implements PresenceChannel {
    /**
     * The Pusher client instance.
     */
    socket: Websocket;

    /**
     * The name of the channel.
     */
    name: string;

    /**
     * Channel options.
     */
    options: object;

    /**
     * The event formatter.
     */
    eventFormatter: EventFormatter;

    /**
     * Create a new class instance.
     */
    constructor(socket: Websocket, name: string, options: object) {
        super();

        this.name = name;
        this.socket = socket;
        this.options = options;
        this.eventFormatter = new EventFormatter(this.options["namespace"]);

        this.subscribe();
    }

    /**
     * Subscribe to a Pusher channel.
     */
    subscribe(): any {
        this.options["debug"] && console.log(`${LOG_PREFIX} subscribe for channel ${this.name} ...`);

        this.socket.subscribe(this)
    }

    /**
     * Unsubscribe from a Pusher channel.
     */
    unsubscribe(): void {
        this.options["debug"] && console.log(`${LOG_PREFIX} unsubscribe for channel ${this.name} ...`);

        this.socket.unsubscribe(this);
    }

    /**
     * Listen for an event on the channel instance.
     */
    listen(event: string, callback: Function): this {
        this.options["debug"] && console.log(`${LOG_PREFIX} listen to ${event} for channel ${this.name} ...`);

        this.on(this.eventFormatter.format(event), callback);

        return this;
    }

    /**
     * Stop listening for an event on the channel instance.
     */
    stopListening(event: string, callback?: Function): this {
        this.options["debug"] && console.log(`${LOG_PREFIX} stop listening to ${event} for channel ${this.name} ...`);

        this.socket.unbindEvent(this, event, callback)

        return this;
    }

    /**
     * Register a callback to be called anytime a subscription succeeds.
     */
    subscribed(callback: Function): this {
        this.options["debug"] && console.log(`${LOG_PREFIX} subscribed for channel ${this.name} ...`);

        this.on('subscription_succeeded', () => {
            callback();
        });

        return this;
    }

    /**
     * Register a callback to be called anytime a subscription error occurs.
     */
    error(callback: Function): this {
        this.options["debug"] && console.log(`${LOG_PREFIX} error for channel ${this.name} ...`);

        this.on('error', (status) => {
            callback(status);
        });

        return this;
    }

    /**
     * Bind a channel to an event.
     */
    on(event: string, callback: Function): Channel {
        this.options["debug"] && console.log(`${LOG_PREFIX} on ${event} for channel ${this.name} ...`);

        this.socket.bind(this, event, callback)

        return this;
    }

    whisper(event: string, data: object): this {
        let channel = this.name;
        let formattedEvent = "client-" + event;
        this.socket.send({
            "event": formattedEvent,
            data,
            channel,
        })

        return this;
    }

    here(callback: Function): this {
        // TODO: implement

        return this
    }

    /**
     * Listen for someone joining the channel.
     */
    joining(callback: Function): this {
        // TODO: implement

        return this
    }

    /**
     * Listen for someone leaving the channel.
     */
    leaving(callback: Function): this {
        // TODO: implement

        return this
    }
}

export { PresenceChannel };
