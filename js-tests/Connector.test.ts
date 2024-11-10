import WS from "jest-websocket-mock";
import { Connector } from "../js-src/Connector";
import { Channel } from "../js-src/Channel";

const mockedHost = 'ws://localhost:1234';

describe('Connector', () => {
    let server: WS;

    beforeEach(() => {
        jest.useRealTimers();
        server = new WS(mockedHost);
    });

    afterEach(() => {
        server.close()
    });

    test('socket id is correctly set', async () => {
        const connector = new Connector({
            host: mockedHost,
        })

        await server.connected;

        await expect(server).toReceiveMessage('{"event":"whoami"}');
        server.send('{"event":"whoami","data":{"socket_id":"test-socket-id"}}')

        expect(connector.socketId()).toBe('test-socket-id')
    })

    test('we reconnect to the server on error', async () => {
        const connector = new Connector({
            host: mockedHost,
        })

        await server.connected;
        await expect(server).toReceiveMessage('{"event":"whoami"}');
        server.send('{"event":"whoami","data":{"socket_id":"test-socket-id"}}')

        server.close();
        await server.closed;
        server.server.stop(() => (server = new WS(mockedHost)));

        await server.connected;
        await expect(server).toReceiveMessage('{"event":"whoami"}');
        server.send('{"event":"whoami","data":{"socket_id":"test-socket-id2"}}')

        expect(connector.socketId()).toBe('test-socket-id2')
    })

    test('we can subscribe to a channel and listen to events', async () => {
        const connector = new Connector({
            host: mockedHost,
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

    test('we can send a whisper event', async () => {
        const connector = new Connector({
            host: mockedHost,
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

        channel.on('client-whisper', handler1)

        server.send('{"event":"client-whisper","data":"whisper","channel":"my-test-channel","data":{}}')

        expect(handler1).toBeCalled();
        expect(handler2).not.toBeCalled();
    })
});
