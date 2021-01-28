import WS from "jest-websocket-mock";
import {Connector} from "../dist/Connector";

describe('Connector', () => {
    let server;

    beforeEach(() => {
        server = new WS("ws://localhost:1234");
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
});
