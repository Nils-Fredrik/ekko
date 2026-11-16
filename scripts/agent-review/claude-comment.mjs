import { createInterface } from 'node:readline';
import { pathToFileURL } from 'node:url';
import { sameSnapshot, claudeVerdict } from './policy.mjs';

export const claudeBody = (result, head) =>
    `Claude Opus 5.5 · commit ${head}\n\n${result.summary}\n\n${result.conclusion}`;

// One fixed-purpose MCP tool. The action supplies its Claude App token to subprocesses.
// Neither repository, PR, revision, URL nor token can be supplied by the model.
export async function publishClaudeReview(input, { env = process.env, request = fetch } = {}) {
    const result = claudeVerdict(JSON.stringify(input));
    if (
        /(?:gh[opsu]_|github_pat_|sk-ant-|-----BEGIN [A-Z ]*PRIVATE KEY-----)/.test(result.summary) ||
        Object.entries(env).some(
            ([name, value]) =>
                /TOKEN|SECRET|KEY|PASSWORD|CREDENTIAL/i.test(name) &&
                typeof value === 'string' &&
                value.length >= 16 &&
                result.summary.includes(value),
        )
    ) {
        throw new Error('Review text may contain credentials; publication refused');
    }
    const snapshot = JSON.parse(Buffer.from(env.SNAPSHOT, 'base64').toString());
    const number = Number(env.PR_NUMBER);
    if (env.GITHUB_REPOSITORY !== 'Nils-Fredrik/ekko' || !Number.isSafeInteger(number) || number < 1 || !env.GH_TOKEN)
        throw new Error('Invalid review context');
    const root = `https://api.github.com/repos/Nils-Fredrik/ekko`;
    const options = {
        redirect: 'error',
        signal: AbortSignal.timeout(30_000),
        headers: {
            authorization: `Bearer ${env.GH_TOKEN}`,
            accept: 'application/vnd.github+json',
            'content-type': 'application/json',
        },
    };
    const current = await request(`${root}/pulls/${number}`, options);
    if (!current.ok || !sameSnapshot(await current.json(), snapshot)) throw new Error('Review context changed');
    const posted = await request(`${root}/issues/${number}/comments`, {
        ...options,
        method: 'POST',
        body: JSON.stringify({ body: claudeBody(result, snapshot.head) }),
    });
    if (!posted.ok) throw new Error('Claude comment could not be published');
    const comment = await posted.json();
    if (comment.user?.id !== 209825114 || !Number.isSafeInteger(comment.id))
        throw new Error('Expected Claude App identity');
    return { conclusion: result.conclusion, summary: result.summary, comment_id: comment.id };
}

async function serve() {
    const lines = createInterface({ input: process.stdin });
    for await (const line of lines) {
        let message;
        try {
            message = JSON.parse(line);
            if (message.id === undefined) continue;
            let result;
            if (message.method === 'initialize')
                result = {
                    protocolVersion: '2025-03-26',
                    capabilities: { tools: {} },
                    serverInfo: { name: 'ekko-review', version: '1' },
                };
            else if (message.method === 'tools/list')
                result = {
                    tools: [
                        {
                            name: 'publish_review',
                            description:
                                'Publish your final review on the current PR as Claude. Return the complete tool result unchanged as your structured result, including the exact summary.',
                            inputSchema: {
                                type: 'object',
                                properties: {
                                    conclusion: { type: 'string', enum: ['GODKJENT', 'ENDRINGER KREVES'] },
                                    summary: { type: 'string', minLength: 1, maxLength: 50000 },
                                },
                                required: ['conclusion', 'summary'],
                                additionalProperties: false,
                            },
                        },
                    ],
                };
            else if (message.method === 'tools/call' && message.params?.name === 'publish_review')
                result = {
                    content: [
                        { type: 'text', text: JSON.stringify(await publishClaudeReview(message.params.arguments)) },
                    ],
                };
            else throw new Error('Unsupported tool operation');
            process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id: message.id, result }) + '\n');
        } catch {
            if (message?.id !== undefined)
                process.stdout.write(
                    JSON.stringify({
                        jsonrpc: '2.0',
                        id: message.id,
                        error: { code: -32603, message: 'Review publication failed; no approval granted.' },
                    }) + '\n',
                );
        }
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) await serve();
