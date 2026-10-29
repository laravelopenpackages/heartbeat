/**
 * Heartbeat — the browser side. One timer for the whole page: it asks the server which of
 * the topics you listen to changed since the last beat, and calls only their listeners.
 *
 *   import { createHeartbeat } from './vendor/heartbeat/heartbeat.js';
 *
 *   const heartbeat = createHeartbeat({ url: '/heartbeat' });
 *   heartbeat.on('counts', () => refreshBadges());
 *   heartbeat.on('reminders', () => refreshBell());
 *   heartbeat.start();
 *
 * It waits `interval` seconds between beats (the server says how long), pauses while the
 * tab is hidden and beats at once when it's shown again, never sends two beats at the same
 * time, and backs off (up to 5 minutes) while the server is failing. `beatNow()` asks
 * straight away — e.g. right after the user saved something.
 *
 * Framework-free and dependency-free (fetch + setTimeout). Works with Angular, Vue, React,
 * Livewire/Alpine or plain pages.
 */

const MAX_BACKOFF_MS = 5 * 60 * 1000;

/**
 * @param {object} options
 * @param {string} options.url                    the beat endpoint (route name `heartbeat`)
 * @param {number} [options.interval=15]          seconds between beats until the server says otherwise
 * @param {Record<string,string>} [options.headers] extra request headers
 * @param {RequestCredentials} [options.credentials='same-origin']
 * @param {(error: unknown) => void} [options.onError]
 * @param {typeof fetch} [options.fetch]          a fetch to use instead of the global one
 */
export function createHeartbeat(options) {
    const doFetch = options.fetch ?? globalThis.fetch.bind(globalThis);
    /** @type {Map<string, Set<(topic: string) => void>>} */
    const listeners = new Map();

    let interval = (options.interval ?? 15) * 1000;
    let since = null;
    let timer = null;
    let inFlight = false;
    let running = false;
    let failures = 0;

    const visible = () => typeof document === 'undefined' || document.visibilityState === 'visible';

    function schedule(ms) {
        clearTimeout(timer);
        if (running) {
            timer = setTimeout(beat, ms);
        }
    }

    async function beat() {
        if (!running || inFlight) {
            return;
        }
        if (!visible()) {
            return; // resumes on visibilitychange
        }
        inFlight = true;
        try {
            const params = new URLSearchParams();
            if (since !== null) {
                params.set('since', String(since));
            }
            params.set('topics', [...listeners.keys()].join(','));

            const response = await doFetch(`${options.url}?${params}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(options.headers ?? {}) },
                credentials: options.credentials ?? 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok) {
                throw new Error(`Heartbeat: HTTP ${response.status}`);
            }
            const answer = await response.json();

            since = answer.now;
            interval = Math.max(1, answer.interval ?? interval / 1000) * 1000;
            failures = 0;

            for (const topic of answer.changed ?? []) {
                for (const listener of listeners.get(topic) ?? []) {
                    try {
                        listener(topic);
                    } catch (error) {
                        options.onError?.(error);
                    }
                }
            }
            schedule(interval);
        } catch (error) {
            failures++;
            options.onError?.(error);
            schedule(Math.min(MAX_BACKOFF_MS, interval * 2 ** failures));
        } finally {
            inFlight = false;
        }
    }

    function onVisibility() {
        if (running && visible()) {
            beat();
        }
    }

    return {
        /** Call `listener` whenever `topic` changed. Returns a function that stops listening. */
        on(topic, listener) {
            if (!listeners.has(topic)) {
                listeners.set(topic, new Set());
            }
            listeners.get(topic).add(listener);
            return () => {
                listeners.get(topic)?.delete(listener);
                if (listeners.get(topic)?.size === 0) {
                    listeners.delete(topic);
                }
            };
        },

        /** Start beating (the first beat only learns the server's `now`). */
        start() {
            if (running) {
                return;
            }
            running = true;
            if (typeof document !== 'undefined') {
                document.addEventListener('visibilitychange', onVisibility);
            }
            beat();
        },

        /** Beat now instead of waiting — e.g. right after the user saved something. */
        beatNow() {
            beat();
        },

        stop() {
            running = false;
            clearTimeout(timer);
            if (typeof document !== 'undefined') {
                document.removeEventListener('visibilitychange', onVisibility);
            }
        },
    };
}
