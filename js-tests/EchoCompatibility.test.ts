import WS from "jest-websocket-mock";
import EchoV2 from "laravel-echo";
import { Connector, broadcaster } from "../js-src/Connector";
import { identify, mockedHost, mockFetchResponse } from "./helpers";

// laravel-echo v1, installed under an alias. Its CommonJS build exports the class itself.
// eslint-disable-next-line @typescript-eslint/no-var-requires
const echoV1Module = require("laravel-echo-v1");
const EchoV1 = echoV1Module.default ?? echoV1Module;

const echoVersions: Array<[string, any]> = [
    ['laravel-echo v1', EchoV1],
    ['laravel-echo v2', EchoV2],
];

describe.each(echoVersions)('%s', (_version, Echo) => {
    let server: WS;
    let echo: any;

    beforeEach(() => {
        server = new WS(mockedHost, { jsonProtocol: true });
    });

    afterEach(() => {
        echo?.disconnect();
        echo = undefined;
        WS.clean();
    });

    test.each([
        ['Connector class', Connector],
        ['broadcaster export', broadcaster],
    ])('accepts the %s as broadcaster', async (_label, broadcasterOption) => {
        echo = new Echo({ broadcaster: broadcasterOption, host: mockedHost });

        await identify(server, 'socket-1');

        expect(echo.connector).toBeInstanceOf(Connector);
        expect(echo.socketId()).toBe('socket-1');
    });

    test('authorizes a private channel and delivers namespaced events', async () => {
        const fetchMock = mockFetchResponse({ auth: 'signature' });

        echo = new Echo({
            broadcaster: Connector,
            host: mockedHost,
            authEndpoint: 'https://app.test/broadcasting/auth',
            bearerToken: 'secret-token',
        });

        const handler = jest.fn();
        echo.private('orders').listen('OrderCreated', handler);

        await identify(server, 'socket-1');

        await expect(server).toReceiveMessage({
            event: 'subscribe',
            data: { channel: 'private-orders', auth: 'signature' },
        });

        expect(fetchMock).toHaveBeenCalledWith('https://app.test/broadcasting/auth', expect.objectContaining({
            method: 'POST',
            headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
            body: JSON.stringify({ socket_id: 'socket-1', channel_name: 'private-orders' }),
        }));

        server.send({ event: 'App\\Events\\OrderCreated', channel: 'private-orders', data: { id: 1 } });

        expect(handler).toHaveBeenCalledWith({ id: 1 });
    });

    test('leaving a channel unsubscribes from it', async () => {
        echo = new Echo({ broadcaster: Connector, host: mockedHost });

        echo.channel('news');
        await identify(server, 'socket-1');
        await expect(server).toReceiveMessage({ event: 'subscribe', data: { channel: 'news' } });

        echo.leave('news');

        await expect(server).toReceiveMessage({ event: 'unsubscribe', data: { channel: 'news' } });
    });
});

describe('Connector', () => {
    afterEach(() => WS.clean());

    test('does not open a connection when constructed without options', () => {
        // laravel-echo v2 probes custom broadcasters by calling `new broadcaster()` without arguments.
        const server = new WS(mockedHost, { jsonProtocol: true });

        expect(() => new Connector()).not.toThrow();
        expect(server.server.clients()).toHaveLength(0);
    });
});
