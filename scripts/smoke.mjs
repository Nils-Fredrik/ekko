import { createHash, randomBytes } from 'node:crypto';
import { chromium, request } from '@playwright/test';

// No traces, screenshots, response bodies, credential values or raw errors reach CI logs.
const required = [
    'SMOKE_EMAIL',
    'SMOKE_PASSWORD',
    'SMOKE_REST_TOKEN',
    'SMOKE_TENANT_ID',
    'SMOKE_BRAND_ID',
    'SMOKE_MCP_CLIENT_ID',
];
const check = (condition, label) => {
    if (!condition) throw new Error(label);
};
let browser, api, refreshToken;
let stage = 'configuration';
let failed = false;
try {
    for (const key of required) check(process.env[key], `Missing ${key}`);
    const base = new URL(process.argv[2]);
    check(
        base.protocol === 'https:' || (base.protocol === 'http:' && ['127.0.0.1', 'localhost'].includes(base.hostname)),
        'HTTPS required',
    );
    check(
        base.pathname === '/' && !base.username && !base.password && !base.search && !base.hash,
        'Candidate origin required',
    );
    const origin = base.origin;
    const brand = Number(process.env.SMOKE_BRAND_ID);
    const tenant = Number(process.env.SMOKE_TENANT_ID);
    check(
        Number.isSafeInteger(brand) && brand > 0 && Number.isSafeInteger(tenant) && tenant > 0,
        'Invalid fixture IDs',
    );
    api = await request.newContext({ baseURL: origin, timeout: 20000, maxRedirects: 0 });
    browser = await chromium.launch();
    const context = await browser.newContext({ baseURL: origin });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    let browserError = false;
    page.on('pageerror', () => {
        browserError = true;
    });
    // Never follow an application redirect or send credentials to a different host.
    await context.route('**/*', (route) =>
        new URL(route.request().url()).origin === origin ? route.continue() : route.abort(),
    );

    stage = 'login';
    check((await page.goto('/login')).status() === 200, 'Login page failed');
    await page.locator('#email').fill(process.env.SMOKE_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(`${origin}/dashboard`);
    stage = 'dashboard';
    await page.locator(`a[href$="/brands/${brand}"]`).first().waitFor({ state: 'visible' });
    stage = 'dashboard JavaScript';
    check(!browserError, 'Dashboard JavaScript failed');
    stage = 'dashboard HTTP';
    const version = await page
        .locator('#app')
        .getAttribute('data-page')
        .then((value) => JSON.parse(value).version);
    const inertiaHeaders = { 'X-Inertia': 'true', 'X-Inertia-Version': version, Accept: 'application/json' };
    const dashboard = await page.request.get('/dashboard', { headers: inertiaHeaders, maxRedirects: 0 });
    check(dashboard.status() === 200, 'Authenticated dashboard failed');
    stage = 'dashboard JSON';
    const home = await dashboard.json();
    check(home.component === 'Home' && home.props.brands.some((row) => row.id === brand), 'Dashboard fixture absent');
    stage = 'dashboard tenant';
    check(home.props.auth.user.current_team_id === tenant, 'Dashboard tenant mismatch');

    stage = 'REST';
    const rest = await api.get(`/api/v1/brands/${brand}/metrics`, {
        headers: { Authorization: `Bearer ${process.env.SMOKE_REST_TOKEN}`, Accept: 'application/json' },
    });
    check(rest.status() === 200, 'REST request failed');
    const metrics = await rest.json();
    check(
        Number.isInteger(metrics.data?.summary?.visibility?.n) && Array.isArray(metrics.data?.series?.data),
        'REST metrics contract failed',
    );
    const denied = await api.get('/api/v1/brands', { headers: { Accept: 'application/json' } });
    check(denied.status() === 401, 'REST accepted missing token');

    stage = 'MCP OAuth';
    const verifier = randomBytes(48).toString('base64url');
    const state = randomBytes(24).toString('hex');
    // Register this loopback callback once during bootstrap. No listener or outbound callback is used.
    const redirect = 'http://127.0.0.1:8765/callback';
    const params = {
        response_type: 'code',
        client_id: process.env.SMOKE_MCP_CLIENT_ID,
        redirect_uri: redirect,
        code_challenge: createHash('sha256').update(verifier).digest('base64url'),
        code_challenge_method: 'S256',
        state,
        scope: 'ekko:read',
    };
    const consent = await page.request.get(`/oauth/authorize?${new URLSearchParams(params)}`, {
        headers: inertiaHeaders,
        maxRedirects: 0,
    });
    stage = 'MCP consent HTTP';
    check(consent.status() === 200, 'MCP consent failed');
    const consentPage = await consent.json();
    stage = 'MCP consent tenant';
    check(
        consentPage.component === 'Mcp/Authorize' && consentPage.props.tenants?.some((row) => row.id === tenant),
        'MCP tenant absent',
    );
    const codeResponse = await page.request.post('/oauth/authorize', {
        form: { ...params, _token: consentPage.props.csrf, tenant, decision: 'approve', allow_write: '0' },
        maxRedirects: 0,
    });
    stage = 'MCP authorization code';
    check(codeResponse.status() === 302, 'MCP authorization failed');
    const callback = new URL(codeResponse.headers().location);
    stage = 'MCP callback';
    check(
        callback.origin + callback.pathname === redirect &&
            callback.searchParams.get('state') === state &&
            callback.searchParams.get('code'),
        'MCP callback invalid',
    );
    const exchange = await api.post('/oauth/token', {
        form: {
            grant_type: 'authorization_code',
            client_id: params.client_id,
            redirect_uri: redirect,
            code_verifier: verifier,
            code: callback.searchParams.get('code'),
        },
    });
    stage = 'MCP token exchange';
    check(exchange.status() === 200, 'MCP token exchange failed');
    const tokens = await exchange.json();
    refreshToken = tokens.refresh_token;
    check(
        typeof tokens.access_token === 'string' && typeof refreshToken === 'string' && tokens.scope === 'ekko:read',
        'MCP token contract failed',
    );
    const headers = { Authorization: `Bearer ${tokens.access_token}`, Accept: 'application/json, text/event-stream' };
    const rpc = async (id, method, params) => {
        const response = await api.post('/mcp', { headers, data: { jsonrpc: '2.0', id, method, params } });
        check(response.status() === 200, 'MCP HTTP failure');
        const type = response.headers()['content-type'] ?? '';
        let body;
        if (type.includes('text/event-stream')) {
            const messages = (await response.text())
                .split('\n')
                .filter((line) => line.startsWith('data:'))
                .map((line) => JSON.parse(line.slice(5)));
            body = messages.find((message) => message.id === id);
        } else body = await response.json();
        check(body?.id === id && !body.error && body.result && !body.result.isError, 'MCP RPC failure');
        if (response.headers()['mcp-session-id']) headers['Mcp-Session-Id'] = response.headers()['mcp-session-id'];
        return body.result;
    };
    stage = 'MCP initialize';
    const initialized = await rpc(1, 'initialize', {
        protocolVersion: '2025-06-18',
        capabilities: {},
        clientInfo: { name: 'ekko-deploy-smoke', version: '1.0' },
    });
    check(initialized.serverInfo?.name === 'Ekko', 'Unexpected MCP server');
    headers['MCP-Protocol-Version'] = initialized.protocolVersion;
    const notification = await api.post('/mcp', {
        headers,
        data: { jsonrpc: '2.0', method: 'notifications/initialized' },
    });
    check([200, 202, 204].includes(notification.status()), 'MCP initialization notification failed');
    stage = 'MCP whoami';
    const who = await rpc(2, 'tools/call', { name: 'whoami', arguments: {} });
    const identity = who.structuredContent;
    check(
        identity?.can_write === false &&
            identity?.tenants?.some((row) => row.id === tenant && row.chosen_when_connecting),
        'MCP identity contract failed',
    );
    stage = 'MCP tools';
    const list = await rpc(3, 'tools/list', {});
    check(
        list.tools?.some((tool) => tool.name === 'get_visibility'),
        'MCP tool missing',
    );
} catch {
    console.error(`Smoke failed at ${stage}; no credentials or response bodies logged.`);
    failed = true;
} finally {
    if (refreshToken && api) {
        try {
            const result = await api.post('/oauth/revoke', {
                form: { token: refreshToken, client_id: process.env.SMOKE_MCP_CLIENT_ID },
            });
            if (result.status() !== 200) throw new Error();
        } catch {
            console.error('Smoke connection cleanup failed.');
            failed = true;
        }
    }
    await browser?.close();
    await api?.dispose();
}
if (failed) process.exitCode = 1;
else console.log('Authenticated login, rendered dashboard, REST and MCP smoke passed.');
