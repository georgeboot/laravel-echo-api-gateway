import WS from "jest-websocket-mock";

export const mockedHost = 'ws://localhost:1234';

/**
 * Wait for the client to connect and ask for its socket id, then answer with the given id.
 */
export async function identify(server: WS, socketId: string): Promise<void> {
    await server.connected;
    await expect(server).toReceiveMessage({ event: 'whoami' });
    server.send({ event: 'whoami', data: { socket_id: socketId } });
}

/**
 * Close the current mock server and start a fresh one on the same host, simulating a dropped connection.
 */
export async function restartServer(server: WS): Promise<WS> {
    server.close();
    await server.closed;
    WS.clean();

    return new WS(mockedHost, { jsonProtocol: true });
}

export function mockFetchResponse(body: object, ok = true, status = 200): jest.Mock {
    const fetchMock = jest.fn().mockResolvedValue({
        ok,
        status,
        json: () => Promise.resolve(body),
    });

    (globalThis as any).fetch = fetchMock;

    return fetchMock;
}

export const flushPromises = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));
