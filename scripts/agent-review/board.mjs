// Board's configured MCP endpoint. Never send the service credential to a PR-controlled URL.
export async function postReviewBlocker({ token, number, request = fetch }) {
    if (!token || !Number.isSafeInteger(number) || number < 1)
        throw new Error('Missing Board service credential or PR number');
    let session;
    let protocol = '2025-03-26';
    let id = 0;
    async function rpc(method, params, notification = false) {
        const requestId = notification ? undefined : ++id;
        const response = await request('https://board.maksimer.com/mcp', {
            method: 'POST',
            redirect: 'error',
            signal: AbortSignal.timeout(30_000),
            headers: {
                authorization: `Bearer ${token}`,
                accept: 'application/json, text/event-stream',
                'content-type': 'application/json',
                'mcp-protocol-version': protocol,
                ...(session ? { 'mcp-session-id': session } : {}),
            },
            body: JSON.stringify({ jsonrpc: '2.0', ...(notification ? {} : { id: requestId }), method, params }),
        });
        if (!response.ok) throw new Error(`Board MCP returned ${response.status}`);
        session = response.headers.get('mcp-session-id') || session;
        if (notification) return;
        const body = await response.text();
        const messages = response.headers.get('content-type')?.includes('text/event-stream')
            ? body.split(/\r?\n\r?\n/).flatMap((event) => {
                  const data = event
                      .split(/\r?\n/)
                      .filter((line) => line.startsWith('data:'))
                      .map((line) => line.slice(5).trimStart())
                      .join('\n');
                  return data ? [JSON.parse(data)] : [];
              })
            : [JSON.parse(body)];
        const message = messages.find((item) => item.id === requestId);
        if (!message || message.error || message.result?.isError) throw new Error('Board MCP operation failed');
        return message.result;
    }
    const initialized = await rpc('initialize', {
        protocolVersion: protocol,
        capabilities: {},
        clientInfo: { name: 'ekko-agent-review', version: '1' },
    });
    protocol = initialized.protocolVersion;
    await rpc('notifications/initialized', {}, true);
    await rpc('tools/call', { name: 'board_read', arguments: { board: 'vn4w66vc', mode: 'snapshot', budget: 500 } });
    await rpc('tools/call', {
        name: 'board_post',
        arguments: {
            board: 'vn4w66vc',
            mode: 'post',
            task: 42,
            type: 'blocker',
            refs: [`pr:Nils-Fredrik/ekko#${number}`],
            body: `PR #${number} har tre ulike reviewrunder uten godkjenning. Eieren må vurdere videre retting. agent-review forblir rød; se PR og Actions for funn og testbevis.`,
        },
    });
}
