import { DurableObject } from 'cloudflare:workers';

// Wake anyway after this long, so a lost push delays a changed reminder by at most this much.
const SAFETY_SECONDS = 3 * 60 * 60;
// Wake one tick early: the container is warm when the reminder minute arrives.
const PREWARM_SECONDS = 60;

/**
 * One strongly consistent record: the next moment the bot has work, and when that answer was computed.
 */
export class WakeState extends DurableObject {
    async read() {
        return (await this.ctx.storage.get('state')) ?? { next: null, computedAt: 0 };
    }

    /**
     * Keeps the newest computation, so a tick answer computed before a settings push cannot overwrite it.
     */
    async write(next, computedAt) {
        const current = await this.read();
        if (computedAt < current.computedAt) {
            return current;
        }
        const state = { next, computedAt };
        await this.ctx.storage.put('state', state);

        return state;
    }
}

const nowSeconds = () => Math.floor(Date.now() / 1000);

const store = (env) => env.WAKE_STATE.get(env.WAKE_STATE.idFromName('bot'));

const capped = (value, now) => (typeof value === 'number' ? Math.min(value, now + SAFETY_SECONDS) : now + SAFETY_SECONDS);

const computedAt = (body) => (typeof body?.computed_at_ms === 'number' ? body.computed_at_ms : Date.now());

async function authorized(request, env) {
    const expected = new TextEncoder().encode(`Bearer ${env.CRON_SECRET}`);
    const given = new TextEncoder().encode(request.headers.get('Authorization') ?? '');

    return given.byteLength === expected.byteLength && crypto.subtle.timingSafeEqual(given, expected);
}

export default {
    async scheduled(controller, env) {
        const now = nowSeconds();
        const state = await store(env).read();
        if (state.next !== null && now < state.next - PREWARM_SECONDS) {
            return;
        }

        let response;
        try {
            response = await fetch(`${env.BOT_URL}/internal/cron`, {
                method: 'POST',
                headers: { Authorization: `Bearer ${env.CRON_SECRET}` },
            });
        } catch {
            // The stored time stays in the past, so the next minute tries again.
            return;
        }
        if (!response.ok) {
            return;
        }

        const body = await response.json().catch(() => null);
        await store(env).write(capped(body?.next_wake_at, now), computedAt(body));
    },

    async fetch(request, env) {
        if (!(await authorized(request, env))) {
            return new Response('forbidden', { status: 403 });
        }

        const { pathname } = new URL(request.url);
        const now = nowSeconds();

        if (pathname === '/next' && request.method === 'POST') {
            const body = await request.json().catch(() => null);
            const state = await store(env).write(capped(body?.next_wake_at, now), computedAt(body));

            return Response.json({ ok: true, next_wake_at: state.next });
        }

        if (pathname === '/status' && request.method === 'GET') {
            const state = await store(env).read();

            return Response.json({ now, next_wake_at: state.next, computed_at_ms: state.computedAt });
        }

        return new Response('not found', { status: 404 });
    },
};
