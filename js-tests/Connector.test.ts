import WS from "jest-websocket-mock";
import { Connector } from "../js-src/Connector";
import { Channel } from "../js-src/Channel";
import { flushPromises, identify, mockedHost, mockFetchResponse, restartServer } from "./helpers";

describe('Connector', () => {
    let server: WS;
    let connector: Connector;

    beforeEach(() => {
        server = new WS(mockedHost, { jsonProtocol: true });
    });

    afterEach(() => {
        connector?.disconnect();
        connector = undefined;
        WS.clean();
        jest.restoreAllMocks();
    });

    test('socket id is correctly set', async () => {
        connector = new Connector({ host: mockedHost });

        await identify(server, 'test-socket-id');

        expect(connector.socketId()).toBe('test-socket-id');
    });

    test('we reconnect to the server on error', async () => {
        connector = new Connector({ host: mockedHost, reconnectDelay: 10 });

        await identify(server, 'test-socket-id');

        server = await restartServer(server);
        await identify(server, 'test-socket-id2');

        expect(connector.socketId()).toBe('test-socket-id2');
    });

    test('we only open one new connection per reconnect', async () => {
        connector = new Connector({ host: mockedHost, reconnectDelay: 10 });

        await identify(server, 'test-socket-id');

        server = await restartServer(server);
        await identify(server, 'test-socket-id2');
        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(server.server.clients()).toHaveLength(1);
    });

    test('we keep retrying until the server is available, with a single connection', async () => {
        WS.clean();

        connector = new Connector({ host: mockedHost, reconnectDelay: 5, maxReconnectDelay: 20 });
        await new Promise((resolve) => setTimeout(resolve, 60));

        server = new WS(mockedHost, { jsonProtocol: true });
        await identify(server, 'test-socket-id');
        await new Promise((resolve) => setTimeout(resolve, 60));

        expect(connector.socketId()).toBe('test-socket-id');
        expect(server.server.clients()).toHaveLength(1);
    });

    test('we can subscribe to a channel and listen to events', async () => {
        connector = new Connector({ host: mockedHost });

        await identify(server, 'test-socket-id');

        const channel = connector.channel('my-test-channel');

        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'my-test-channel' } });

        expect(channel).toBeInstanceOf(Channel);

        const handler1 = jest.fn();
        const handler2 = jest.fn();

        channel.listen('.my-test-event', handler1);

        server.send({ event: 'my-test-event', channel: 'my-test-channel', data: { id: 1 } });

        expect(handler1).toHaveBeenCalledWith({ id: 1 });
        expect(handler2).not.toHaveBeenCalled();
    });

    test('channels subscribed before the connection is ready are subscribed once identified', async () => {
        connector = new Connector({ host: mockedHost });

        connector.channel('early-channel');

        await identify(server, 'test-socket-id');

        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'early-channel' } });
    });

    test('every listener of the same event is called', async () => {
        connector = new Connector({ host: mockedHost });
        await identify(server, 'test-socket-id');

        const handler1 = jest.fn();
        const handler2 = jest.fn();

        connector.channel('my-test-channel').listen('.my-test-event', handler1);
        connector.channel('my-test-channel').listen('.my-test-event', handler2);
        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'my-test-channel' } });

        server.send({ event: 'my-test-event', channel: 'my-test-channel', data: {} });

        expect(handler1).toHaveBeenCalledTimes(1);
        expect(handler2).toHaveBeenCalledTimes(1);
    });

    test('stopListening with a callback only removes that callback', async () => {
        connector = new Connector({ host: mockedHost });
        await identify(server, 'test-socket-id');

        const handler1 = jest.fn();
        const handler2 = jest.fn();

        const channel = connector.channel('my-test-channel')
            .listen('.my-test-event', handler1)
            .listen('.my-test-event', handler2);
        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'my-test-channel' } });

        channel.stopListening('.my-test-event', handler1);
        server.send({ event: 'my-test-event', channel: 'my-test-channel', data: {} });

        expect(handler1).not.toHaveBeenCalled();
        expect(handler2).toHaveBeenCalledTimes(1);
    });

    test('stopListening without a callback removes every listener of the event', async () => {
        connector = new Connector({ host: mockedHost });
        await identify(server, 'test-socket-id');

        const handler = jest.fn();

        const channel = connector.channel('my-test-channel').listen('.my-test-event', handler);
        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'my-test-channel' } });

        channel.stopListening('.my-test-event');
        server.send({ event: 'my-test-event', channel: 'my-test-channel', data: {} });

        expect(handler).not.toHaveBeenCalled();
    });

    test('channels joined after connecting are resubscribed after a reconnect', async () => {
        connector = new Connector({ host: mockedHost, reconnectDelay: 10 });
        await identify(server, 'test-socket-id');

        const handler = jest.fn();
        connector.channel('late-channel').listen('.my-test-event', handler);
        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'late-channel' } });

        server = await restartServer(server);
        await identify(server, 'test-socket-id2');

        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'late-channel' } });

        server.send({ event: 'my-test-event', channel: 'late-channel', data: {} });
        expect(handler).toHaveBeenCalledTimes(1);
    });

    test('channels that were left are not resubscribed after a reconnect', async () => {
        connector = new Connector({ host: mockedHost, reconnectDelay: 10 });
        connector.channel('left-channel');
        connector.channel('kept-channel');

        await identify(server, 'test-socket-id');
        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'left-channel' } });
        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'kept-channel' } });

        connector.leave('left-channel');
        await expect(server).toReceiveMessage({ event: 'unsubscribe', data: { channel: 'left-channel' } });

        server = await restartServer(server);
        await identify(server, 'test-socket-id2');

        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'kept-channel' } });
        await flushPromises();
        expect(server.messages).not.toContainEqual({ event: 'subscribe', data: { channel: 'left-channel' } });
    });

    test('pings are not duplicated after reconnecting', async () => {
        connector = new Connector({ host: mockedHost, reconnectDelay: 10, pingInterval: 40 });
        await identify(server, 'test-socket-id');

        server = await restartServer(server);
        await identify(server, 'test-socket-id2');

        await new Promise((resolve) => setTimeout(resolve, 210));

        const pings = server.messages.filter((message: any) => message.event === 'ping');
        expect(pings.length).toBeGreaterThanOrEqual(3);
        expect(pings.length).toBeLessThanOrEqual(6);
    });

    test('subscribed callbacks run when the server confirms the subscription', async () => {
        connector = new Connector({ host: mockedHost });
        await identify(server, 'test-socket-id');

        const subscribed = jest.fn();
        connector.channel('my-test-channel').subscribed(subscribed);
        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'my-test-channel' } });

        server.send({ event: 'subscription_succeeded', channel: 'my-test-channel', data: [] });

        expect(subscribed).toHaveBeenCalledTimes(1);
    });

    test('we can send a whisper event and listen for whispers', async () => {
        connector = new Connector({ host: mockedHost });
        mockFetchResponse({ auth: 'signature' });
        await identify(server, 'test-socket-id');

        const channel = connector.privateChannel('my-test-channel');
        await expect(server).toReceiveMessage({
            event: 'subscribe',
            data: { channel: 'private-my-test-channel', auth: 'signature' },
        });

        const handler = jest.fn();
        channel.listenForWhisper('typing', handler);
        channel.whisper('typing', { name: 'Ana' });

        await expect(server).toReceiveMessage({
            event: 'client-typing',
            channel: 'private-my-test-channel',
            data: { name: 'Ana' },
        });

        server.send({ event: 'client-typing', channel: 'private-my-test-channel', data: { name: 'Luis' } });
        expect(handler).toHaveBeenCalledWith({ name: 'Luis' });
    });

    describe('private channel authorization', () => {
        test('posts to the auth endpoint with the configured headers', async () => {
            const fetchMock = mockFetchResponse({ auth: 'signature' });

            connector = new Connector({
                host: mockedHost,
                authEndpoint: 'https://app.test/broadcasting/auth',
                bearerToken: 'secret-token',
                auth: { headers: { 'X-Tenant': 'tenant-1' } },
            });
            connector.privateChannel('orders');

            await identify(server, 'test-socket-id');

            await expect(server).toReceiveMessage({
                event: 'subscribe',
                data: { channel: 'private-orders', auth: 'signature' },
            });
            expect(fetchMock).toHaveBeenCalledWith('https://app.test/broadcasting/auth', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': 'Bearer secret-token',
                    'X-Tenant': 'tenant-1',
                },
                body: JSON.stringify({ socket_id: 'test-socket-id', channel_name: 'private-orders' }),
            });
        });

        test('uses channelAuthorization endpoint and headers when given', async () => {
            const fetchMock = mockFetchResponse({ auth: 'signature' });

            connector = new Connector({
                host: mockedHost,
                channelAuthorization: {
                    endpoint: 'https://api.test/broadcasting/auth',
                    headers: { 'X-Api-Key': 'key' },
                },
            });
            connector.privateChannel('orders');

            await identify(server, 'test-socket-id');
            await expect(server).toReceiveMessage({
                event: 'subscribe',
                data: { channel: 'private-orders', auth: 'signature' },
            });

            expect(fetchMock).toHaveBeenCalledWith('https://api.test/broadcasting/auth', expect.objectContaining({
                headers: expect.objectContaining({ 'X-Api-Key': 'key' }),
            }));
        });

        test('uses channelAuthorization.customHandler when given', async () => {
            const fetchMock = mockFetchResponse({ auth: 'from-fetch' });
            const customHandler = jest.fn((params, callback) => callback(null, { auth: 'from-handler' }));

            connector = new Connector({ host: mockedHost, channelAuthorization: { customHandler } });
            connector.privateChannel('orders');

            await identify(server, 'test-socket-id');
            await expect(server).toReceiveMessage({
                event: 'subscribe',
                data: { channel: 'private-orders', auth: 'from-handler' },
            });

            expect(customHandler).toHaveBeenCalledWith(
                { socketId: 'test-socket-id', channelName: 'private-orders' },
                expect.any(Function),
            );
            expect(fetchMock).not.toHaveBeenCalled();
        });

        test('reports a failed authorization to the channel error callbacks', async () => {
            mockFetchResponse({ message: 'Forbidden' }, false, 403);

            connector = new Connector({ host: mockedHost });
            const onError = jest.fn();
            connector.privateChannel('orders').error(onError);

            await identify(server, 'test-socket-id');
            await flushPromises();
            await flushPromises();

            expect(onError).toHaveBeenCalledWith(expect.objectContaining({ type: 'AuthError', status: 403 }));
            expect(server.messages).not.toContainEqual(expect.objectContaining({ event: 'subscribe' }));
        });

        test('reports a rejected subscription to the channel error callbacks', async () => {
            mockFetchResponse({ auth: 'bad-signature' });

            connector = new Connector({ host: mockedHost });
            const onError = jest.fn();
            connector.privateChannel('orders').error(onError);

            await identify(server, 'test-socket-id');
            await expect(server).toReceiveMessage({
                event: 'subscribe',
                data: { channel: 'private-orders', auth: 'bad-signature' },
            });

            server.send({ event: 'error', channel: 'private-orders', data: { message: 'Invalid auth signature' } });

            expect(onError).toHaveBeenCalledWith({ message: 'Invalid auth signature' });
        });
    });
});
