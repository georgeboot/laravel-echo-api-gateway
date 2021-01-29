import WS from "jest-websocket-mock";
import {Connector} from "../js-src/Connector";
import {Channel} from "../js-src/Channel";

describe('Connector', () => {
    let server: WS;

    beforeEach(() => {
        server = new WS("ws://localhost:1234");
    });

    afterEach(() => {
        server.close()
    });

    test('socket id is correctly set', async () => {
        const connector = new Connector({
            host: "ws://localhost:1234",
        })

        await server.connected;

        await expect(server).toReceiveMessage('{"event":"whoami"}');
        server.send('{"event":"whoami","data":{"socket_id":"test-socket-id"}}')

        expect(connector.socketId()).toBe('test-socket-id')
    })

    test('we can subscribe to a channel and listen to events', async () => {
        const connector = new Connector({
            host: "ws://localhost:1234",
        })

        await server.connected;

        await expect(server).toReceiveMessage('{"event":"whoami"}');
        server.send('{"event":"whoami","data":{"socket_id":"test-socket-id"}}')

        const channel = connector.channel('my-test-channel')

        await expect(server).toReceiveMessage('{"event":"subscribe","data":{"channel":"my-test-channel"}}');

        server.send('{"event":"subscription_succeeded","channel":"my-test-channel"}')

        expect(channel).toBeInstanceOf(Channel)

        const handler1 = jest.fn();
        const handler2 = jest.fn();

        channel.on('my-test-event', handler1)

        server.send('{"event":"my-test-event","channel":"my-test-channel","data":{}}')

        expect(handler1).toBeCalled();
        expect(handler2).not.toBeCalled();
    })
});
