import { DurableObject } from 'cloudflare:workers';

// Wake anyway after this long, so a lost push delays a changed reminder by at most this much.
const SAFETY_SECONDS = 3 * 60 * 60;
// Wake one tick early: the container is warm when the reminder minute arrives.
const PREWARM_SECONDS = 60;
// Vercel stops an idle container after 5 minutes; past this the next request most likely cold-starts.
const COLD_AFTER_MS = 4 * 60 * 1000;

/**
 * Strongly consistent records: the next moment the bot has work (and when that answer was computed),
 * and the last time anything reached the bot.
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

    /**
     * Records a contact with the bot and returns the previous one (0 when never).
     */
    async touch(at) {
        const previous = (await this.ctx.storage.get('lastContactMs')) ?? 0;
        await this.ctx.storage.put('lastContactMs', at);

        return previous;
    }

    async lastContact() {
        return (await this.ctx.storage.get('lastContactMs')) ?? 0;
    }
}

const nowSeconds = () => Math.floor(Date.now() / 1000);

const store = (env) => env.WAKE_STATE.get(env.WAKE_STATE.idFromName('bot'));

const capped = (value, now) => (typeof value === 'number' ? Math.min(value, now + SAFETY_SECONDS) : now + SAFETY_SECONDS);

const computedAt = (body) => (typeof body?.computed_at_ms === 'number' ? body.computed_at_ms : Date.now());

function sameSecret(given, expected) {
    const a = new TextEncoder().encode(given ?? '');
    const b = new TextEncoder().encode(expected ?? '');

    return b.byteLength > 0 && a.byteLength === b.byteLength && crypto.subtle.timingSafeEqual(a, b);
}

const authorized = (request, env) => sameSecret(request.headers.get('Authorization'), `Bearer ${env.CRON_SECRET}`);

function messageChatId(body) {
    try {
        return JSON.parse(body)?.message?.chat?.id;
    } catch {
        return undefined;
    }
}

async function sendTyping(env, chatId) {
    try {
        await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendChatAction`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chat_id: chatId, action: 'typing' }),
        });
    } catch {
        // Cosmetic only; the bot's reply does not depend on it.
    }
}

/**
 * Telegram's webhook lands here and is passed to the bot unchanged; the bot still verifies the secret.
 * When the bot is probably asleep, "typing…" is shown at once so the user sees a reaction during the cold start.
 */
async function relayTelegram(request, env, ctx, pathname) {
    const body = await request.text();

    if (env.TELEGRAM_BOT_TOKEN && sameSecret(request.headers.get('X-Telegram-Bot-Api-Secret-Token'), env.TELEGRAM_WEBHOOK_SECRET)) {
        const now = Date.now();
        const previous = await store(env).touch(now);
        // Only typed messages: a pressed button already shows Telegram's own loading indicator.
        const chatId = now - previous > COLD_AFTER_MS ? messageChatId(body) : undefined;
        if (chatId !== undefined) {
            ctx.waitUntil(sendTyping(env, chatId));
        }
    }

    let response;
    try {
        response = await fetch(`${env.BOT_URL}${pathname}`, {
            method: 'POST',
            headers: {
                'Content-Type': request.headers.get('Content-Type') ?? 'application/json',
                'X-Telegram-Bot-Api-Secret-Token': request.headers.get('X-Telegram-Bot-Api-Secret-Token') ?? '',
            },
            body,
        });
    } catch {
        // A non-2xx answer makes Telegram redeliver the update later.
        return new Response('bot unreachable', { status: 502 });
    }

    return new Response(response.body, {
        status: response.status,
        headers: { 'Content-Type': response.headers.get('Content-Type') ?? 'application/json' },
    });
}

export default {
    async scheduled(controller, env) {
        const now = nowSeconds();
        const state = await store(env).read();
        if (state.next !== null && now < state.next - PREWARM_SECONDS) {
            return;
        }

        await store(env).touch(Date.now());
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

    async fetch(request, env, ctx) {
        const { pathname } = new URL(request.url);

        if (pathname.startsWith('/telegram/webhook/') && request.method === 'POST') {
            return relayTelegram(request, env, ctx, pathname);
        }

        if (!authorized(request, env)) {
            return new Response('forbidden', { status: 403 });
        }

        const now = nowSeconds();

        if (pathname === '/next' && request.method === 'POST') {
            // Pushed by the bot itself, so it is awake right now.
            await store(env).touch(Date.now());
            const body = await request.json().catch(() => null);
            const state = await store(env).write(capped(body?.next_wake_at, now), computedAt(body));

            return Response.json({ ok: true, next_wake_at: state.next });
        }

        if (pathname === '/status' && request.method === 'GET') {
            const state = await store(env).read();

            return Response.json({
                now,
                next_wake_at: state.next,
                computed_at_ms: state.computedAt,
                last_contact_ms: await store(env).lastContact(),
            });
        }

        return new Response('not found', { status: 404 });
    },
};
