import {EventFormatter} from 'laravel-echo/src/util';
import {Channel} from 'laravel-echo/src/channel/channel';
import {PresenceChannel} from "laravel-echo/src/channel";
import {OurConnector} from "./OurWebsocket";

/**
 * This class represents a Pusher channel.
 */
export class ApiGatewayChannel extends Channel implements PresenceChannel {
    /**
     * The Pusher client instance.
     */
    socket: OurConnector;

    /**
     * The name of the channel.
     */
    name: any;

    /**
     * Channel options.
     */
    options: any;

    /**
     * The event formatter.
     */
    eventFormatter: EventFormatter;

    private listeners: { [index: string]: Function } = {};

    /**
     * Create a new class instance.
     */
    constructor(socket: OurConnector, name: any, options: any) {
        super();

        this.name = name;
        this.socket = socket;
        this.options = options;
        this.eventFormatter = new EventFormatter(this.options.namespace);

        this.subscribe();
    }

    /**
     * Subscribe to a Pusher channel.
     */
    subscribe(): any {
        this.socket.subscribe(this)
    }

    /**
     * Unsubscribe from a Pusher channel.
     */
    unsubscribe(): void {
        this.socket.unsubscribe(this.name);
    }

    /**
     * Listen for an event on the channel instance.
     */
    listen(event: string, callback: Function): ApiGatewayChannel {
        this.on(this.eventFormatter.format(event), callback);

        return this;
    }

    /**
     * Stop listening for an event on the channel instance.
     */
    stopListening(event: string, callback?: Function): ApiGatewayChannel {
        if (this.listeners[event] && (!callback || this.listeners[event] === callback)) {
            delete this.listeners[event]
        }

        return this;
    }

    /**
     * Register a callback to be called anytime a subscription succeeds.
     */
    subscribed(callback: Function): ApiGatewayChannel {
        this.on('subscription_succeeded', () => {
            callback();
        });

        return this;
    }

    /**
     * Register a callback to be called anytime a subscription error occurs.
     */
    error(callback: Function): ApiGatewayChannel {
        this.on('error', (status) => {
            callback(status);
        });

        return this;
    }

    /**
     * Bind a channel to an event.
     */
    on(event: string, callback: Function): ApiGatewayChannel {
        this.listeners[event] = callback

        return this;
    }

    handleEvent(event: string, data: object) {
        if (this.listeners[event]) {
            this.listeners[event](event, data)
        }
    }

    whisper(event: string, data: object): ApiGatewayChannel {
        this.socket.send({
            event,
            data,
        })

        return this;
    }

    here(callback: Function): ApiGatewayChannel {
        return this
    }

    /**
     * Listen for someone joining the channel.
     */
    joining(callback: Function): ApiGatewayChannel {
        return this
    }

    /**
     * Listen for someone leaving the channel.
     */
    leaving(callback: Function): ApiGatewayChannel {
        return this
    }
}
