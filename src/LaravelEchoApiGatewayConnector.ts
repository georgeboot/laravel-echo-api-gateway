import {Connector} from "laravel-echo/src/connector/connector";
import {OurConnector} from "./OurWebsocket";
import {ApiGatewayChannel} from "./ApiGatewayChannel";

export const broadcaster = options => new LaravelEchoApiGatewayConnector(options);

export class LaravelEchoApiGatewayConnector extends Connector {
    /**
     * The Socket.io connection instance.
     */
    socket: OurConnector;

    /**
     * All of the subscribed channel names.
     */
    channels: { [name: string]: ApiGatewayChannel } = {};

    /**
     * Create a fresh Socket.io connection.
     */
    connect(): void {
        this.socket = new OurConnector(this.options);

        return null;

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
    channel(name: string): ApiGatewayChannel {
        if (!this.channels[name]) {
            this.channels[name] = new ApiGatewayChannel(this.socket, name, this.options);
        }

        return this.channels[name];
    }

    /**
     * Get a private channel instance by name.
     */
    privateChannel(name: string): ApiGatewayChannel {
        if (!this.channels['private-' + name]) {
            this.channels['private-' + name] = new ApiGatewayChannel(this.socket, 'private-' + name, this.options);
        }

        return this.channels['private-' + name] as ApiGatewayChannel;
    }

    /**
     * Get a presence channel instance by name.
     */
    presenceChannel(name: string): ApiGatewayChannel {
        if (!this.channels['presence-' + name]) {
            this.channels['presence-' + name] = new ApiGatewayChannel(
                this.socket,
                'presence-' + name,
                this.options
            );
        }

        return this.channels['presence-' + name] as ApiGatewayChannel;
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
