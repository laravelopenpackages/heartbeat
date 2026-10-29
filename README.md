# Heartbeat

**One tiny poll tells every open page what changed — live-feeling Laravel apps without WebSockets.**

Badges, counters, bells and lists that update on their own, with no Reverb, Pusher or
socket server to run or pay for. Each open page "beats" every few seconds with one small
request: *"which of my topics changed since last time?"* The answer is almost always
"none" — a few bytes, no database work. When something did change, only the parts of the
page listening for it refresh.

```
            every ~15s, only while the tab is visible
browser ──── GET /heartbeat?since=1730198400123&topics=counts,reminders ───► Laravel
        ◄─── { "now": 1730198415456, "changed": ["counts"], "interval": 15 } ──
                       │
                       └─► only the "counts" listeners refresh
```

- **One timer per page**, however many things listen.
- **Cheap**: "last changed" marks are timestamps in your cache — no table, no queries.
- **Per user or for everyone**: touch a topic globally, or just for one user.
- **Time-based topics too**: a watcher answers "did a reminder become due since your last beat?".
- **Polite client**: pauses while the tab is hidden, beats at once when it's shown,
  never overlaps requests, backs off while the server is failing.
- **No clock trouble**: the client sends back the server's own `now`.

Laravel 11, 12 and 13 · PHP 8.2+ · works under Octane.

## Installation

```bash
composer require laravelopenpackages/heartbeat
```

Optionally publish the config and the browser client:

```bash
php artisan vendor:publish --tag=heartbeat-config   # config/heartbeat.php
php artisan vendor:publish --tag=heartbeat-js       # resources/js/vendor/heartbeat/
```

Marks are kept in your cache, so use a store **every web server shares** (redis, database,
memcached…) — not `array`. Set `HEARTBEAT_CACHE_STORE` to use a different store than the
default.

## On the server

### Mark a topic as changed

```php
use LaravelOpenPackages\Heartbeat\Facades\Heartbeat;

Heartbeat::touch('invoices');                  // for everyone
Heartbeat::touch(['counts', 'todos']);         // several at once
Heartbeat::touch('reminders', $user);          // only for this user (or a user id)
```

A topic is any name: letters, digits and `- _ . :` — e.g. `counts`, `invoices`, `invoice:42`.

### …or let a model do it

```php
use LaravelOpenPackages\Heartbeat\Concerns\BeatsOnChange;

class Invoice extends Model
{
    use BeatsOnChange;

    protected array $heartbeatTopics = ['invoices', 'counts'];   // default: 'invoices' (plural, kebab-case)
}
```

Creating, updating, deleting or restoring an invoice touches those topics. To touch them
only for particular users, return them from `heartbeatFor()`:

```php
public function heartbeatFor(): ?iterable
{
    return [$this->assigned_to_id];    // null (the default) = everyone
}
```

> Model events don't fire for query-builder writes (`Invoice::whereIn(...)->update()`,
> `insert()`, `DB::table()`): call `Heartbeat::touch()` yourself there.

### Topics that change with time

Some things change without anyone saving anything — a reminder *becomes* due. Register a
watcher once, in a service provider's `boot()`:

```php
Heartbeat::watch('reminders', function (?User $user, CarbonImmutable $since, CarbonImmutable $now): bool {
    return $user && $user->reminders()->whereBetween('due_at', [$since, $now])->exists();
});
```

It runs on beats that ask for the topic (unless a touch already answered), so keep it to a
quick, indexed query. Register watchers at boot only — never per request.

## The endpoint

`GET /heartbeat?since=<ms>&topics=a,b` (or `topics[]=a&topics[]=b`), route name `heartbeat`.

| | |
|---|---|
| `now` | Server time in ms — send it back as `since` on the next beat. |
| `changed` | The asked-for topics changed since `since` (yours, global ones, and watchers). |
| `interval` | Seconds to wait before the next beat (`config('heartbeat.interval')`). |

The first beat (no `since`) only answers `now`. By default the route uses the `web` and
`auth` middleware — change `heartbeat.route.middleware` to fit your app (e.g.
`['api', 'auth:sanctum']` for a SPA).

## In the browser

A small, dependency-free client (`resources/js/heartbeat.js`, with TypeScript types):

```js
import { createHeartbeat } from './vendor/heartbeat/heartbeat.js';

const heartbeat = createHeartbeat({ url: '/heartbeat' });

heartbeat.on('counts', () => refreshBadges());
const stop = heartbeat.on('invoices', () => reloadInvoiceList());   // stop() to stop listening

heartbeat.start();

// After the user saves something, don't wait for the next beat:
heartbeat.beatNow();
```

Options: `url`, `interval` (seconds, until the server says otherwise), `headers`,
`credentials` (default `same-origin`), `onError`, and `fetch` (to use your own — e.g. one that
adds an auth header).

It works with any front end — Angular, Vue, React, Livewire/Alpine or plain pages. Or speak
the protocol above from your own HTTP client: it's one GET.

## Configuration

`config/heartbeat.php`:

| Key | Default | |
|---|---|---|
| `route.enabled` | `true` | Register the endpoint. |
| `route.path` | `heartbeat` | Its URL. |
| `route.middleware` | `['web', 'auth']` | Who may beat. |
| `route.name` | `heartbeat` | Its route name. |
| `interval` | `15` (`HEARTBEAT_INTERVAL`) | Seconds between beats, sent to every client. |
| `cache.store` | default store (`HEARTBEAT_CACHE_STORE`) | Where marks live — shared by all servers. |
| `cache.prefix` | `heartbeat` | Key prefix. |
| `cache.ttl` | 7 days | Marks older than this are forgotten. |
| `max_topics` | `50` | Topics one beat may ask about. |

## Testing

```bash
composer test          # PHP: Orchestra Testbench
node --test tests/js   # the browser client (Node 20+)
```

## License

MIT — see [LICENSE](LICENSE).
