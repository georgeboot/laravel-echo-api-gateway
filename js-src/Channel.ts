import { EventFormatter } from "./EventFormatter";
import { Websocket } from "./Websocket";
import type { Options } from "./Websocket";

const LOG_PREFIX = '[LE-AG-Channel]';

const NOTIFICATION_EVENT = '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated';

/**
 * A channel on the API Gateway websocket. It implements the public, private and presence channel
 * interfaces of laravel-echo (v1 and v2) without extending its internal classes.
 */
export class Channel {
    /**
     * The websocket connection.
     */
    socket: Websocket;

    /**
     * The name of the channel.
     */
    name: string;

    /**
     * Channel options.
     */
    options: Options;

    /**
     * The event formatter.
     */
    eventFormatter: EventFormatter;

    /**
     * Create a new class instance.
     */
    constructor(socket: Websocket, name: string, options: Options) {
        this.name = name;
        this.socket = socket;
        this.options = options;
        this.eventFormatter = new EventFormatter(this.options.namespace);

        this.subscribe();
    }

    /**
     * Subscribe to the channel.
     */
    subscribe(): void {
        this.options.debug && console.log(`${LOG_PREFIX} subscribe for channel ${this.name} ...`);

        this.socket.subscribe(this);
    }

    /**
     * Unsubscribe from the channel.
     */
    unsubscribe(): void {
        this.options.debug && console.log(`${LOG_PREFIX} unsubscribe for channel ${this.name} ...`);

        this.socket.unsubscribe(this);
    }

    /**
     * Listen for an event on the channel instance.
     */
    listen(event: string, callback: Function): this {
        this.options.debug && console.log(`${LOG_PREFIX} listen to ${event} for channel ${this.name} ...`);

        return this.on(this.eventFormatter.format(event), callback);
    }

    /**
     * Stop listening for an event on the channel instance. Without a callback, every listener of the event is removed.
     */
    stopListening(event: string, callback?: Function): this {
        this.options.debug && console.log(`${LOG_PREFIX} stop listening to ${event} for channel ${this.name} ...`);

        this.socket.unbind(this, this.eventFormatter.format(event), callback);

        return this;
    }

    /**
     * Listen for a whisper event on the channel instance.
     */
    listenForWhisper(event: string, callback: Function): this {
        return this.listen('.client-' + event, callback);
    }

    /**
     * Stop listening for a whisper event on the channel instance.
     */
    stopListeningForWhisper(event: string, callback?: Function): this {
        return this.stopListening('.client-' + event, callback);
    }

    /**
     * Listen for an event on the channel instance.
     */
    notification(callback: Function): this {
        return this.listen(NOTIFICATION_EVENT, callback);
    }

    /**
     * Stop listening for notification events on the channel instance.
     */
    stopListeningForNotification(callback?: Function): this {
        return this.stopListening(NOTIFICATION_EVENT, callback);
    }

    /**
     * Register a callback to be called anytime a subscription succeeds.
     */
    subscribed(callback: Function): this {
        return this.on('subscription_succeeded', () => {
            callback();
        });
    }

    /**
     * Register a callback to be called anytime a subscription or authorization error occurs.
     */
    error(callback: Function): this {
        return this.on('error', callback);
    }

    /**
     * Bind a channel to an event.
     */
    on(event: string, callback: Function): this {
        this.options.debug && console.log(`${LOG_PREFIX} on ${event} for channel ${this.name} ...`);

        this.socket.bind(this, event, callback);

        return this;
    }

    /**
     * Send a client event to the other members of the channel.
     */
    whisper(event: string, data: unknown): this {
        this.socket.send({
            event: 'client-' + event,
            channel: this.name,
            data,
        });

        return this;
    }

    /**
     * Presence channels are not supported yet: member tracking is not implemented on the server.
     */
    here(_callback: Function): this {
        return this;
    }

    /**
     * Presence channels are not supported yet: member tracking is not implemented on the server.
     */
    joining(_callback: Function): this {
        return this;
    }

    /**
     * Presence channels are not supported yet: member tracking is not implemented on the server.
     */
    leaving(_callback: Function): this {
        return this;
    }
}
