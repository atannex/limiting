# Changelog

## v1.0.0 (2026-10-26)

- Eight atomic algorithms on every store: sliding window (default), sliding log, fixed window, token bucket, leaky bucket (shaping), GCRA, calendar quotas and concurrency; costs and refunds everywhere.
- Stores: Redis (Lua compare-and-set), any Laravel cache store (per-key lock) and memory; fail open or fail closed per limit.
- Named policies in `config/limiting.php` with several limits each; a refused request is refunded everywhere it was counted.
- Brute-force protection: `count: failures`, cooldowns, an escalating jail across every policy and route, allow and deny lists with CIDR ranges.
- Keys that are hard to dodge and private: IPv6 grouped per /64, e-mail and input normalization, subnet and fingerprint keys, HMAC-hashed store keys.
- `limit` and `limit.shield` middleware, the `#[RateLimit]` attribute for atannex/routing, `Limiting::attempt()`, queue job middleware and `Limiting::guzzle()` for outgoing calls.
- 429 responses with `Retry-After`, IETF `RateLimit-Policy` / `RateLimit` and `X-RateLimit-*` headers, RFC 9457 problem details.
- Shadow mode and dry runs; events, logs and stats.
- Artisan: `limiting:doctor`, `policies`, `inspect`, `reset`, `jail`, `release`, `jailed`, `stats`.
- `Limiting::fake()` for tests.
