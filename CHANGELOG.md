# Changelog

## 1.0.0 — 2026-10-29

- `Heartbeat::touch()` for everyone or one user; `Heartbeat::watch()` for time-based topics.
- `GET /heartbeat?since=&topics=` endpoint answering `{ now, changed, interval }`.
- `BeatsOnChange` model trait (`$heartbeatTopics`, `heartbeatFor()`).
- Dependency-free browser client with TypeScript types.
- Laravel 11, 12 and 13; PHP 8.2+.
