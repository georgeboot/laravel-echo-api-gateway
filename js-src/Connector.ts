import {Connector as BaseConnector} from "laravel-echo/src/connector/connector";
import {Websocket} from "./Websocket";
import {Channel} from "./Channel";

export const broadcaster = (options: object): Connector => new Connector(options);

export class Connector extends BaseConnector {
    /**
     * The Socket.io connection instance.
     */
    socket: Websocket;

    /**
     * All of the subscribed channel names.
     */
    channels: { [name: string]: Channel } = {};

    /**
     * Create a fresh Socket.io connection.
     */
    connect(): void {
        this.socket = new Websocket(this.options);

        return;

        //
        // this.socket.on('reconnect', () => {
        //     Object.values(this.channels).forEach((channel) => {
        //         channel.subscribe();
        //     });
        // });
        //
        // return this.socket;
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
        if (!this.channels['private-' + name]) {
            this.channels['private-' + name] = new Channel(this.socket, 'private-' + name, this.options);
        }

        return this.channels['private-' + name] as Channel;
    }

    /**
     * Get a presence channel instance by name.
     */
    presenceChannel(name: string): Channel {
        if (!this.channels['presence-' + name]) {
            this.channels['presence-' + name] = new Channel(
                this.socket,
                'presence-' + name,
                this.options
            );
        }

        return this.channels['presence-' + name] as Channel;
    }

    /**
     * Leave the given channel, as well as its private and presence variants.
     */
    leave(name: string): void {
        let channels = [name, 'private-' + name, 'presence-' + name];

        channels.forEach((name) => {
            this.leaveChannel(name);
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
    socketId(): string {
        return this.socket.getSocketId();
    }

    /**
     * Disconnect socket connection.
     */
    disconnect(): void {
        this.socket.close();
    }
}
