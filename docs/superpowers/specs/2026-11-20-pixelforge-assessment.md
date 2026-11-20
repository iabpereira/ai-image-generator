# Pixelforge Image Generator — Spec

Source: https://pixelforge.promptedit.dev/assessment and https://pixelforge.promptedit.dev/docs (read 2026-11-20).

## Product requirements

### Phase 1 — Mandatory
1. **Generate page** — prompt, size, quality, optional reference image URLs. Show the cost **before** submitting (an estimate).
2. **Pricing** — user pays Pixelforge cost **+ 25% markup**. Actual cost is only known after completion and must be recorded then. Failed generations cost **nothing**. Sub-cent handling is our decision.
3. **History** — list of the user's generations with live status (queued, processing, succeeded, failed), estimated cost, actual cost, and the image. Pixelforge image URLs expire after 24h.
4. **Webhooks** — verify signatures; reach the correct final state under duplicate and out-of-order events; an event delivered twice is never recorded twice.
5. **Auth** — users sign in. The app records what each generation cost the user.
6. **Deploy publicly**, with a few generations left on it so the reviewers can compare numbers with Pixelforge's records.

### Phase 2 — Stretch goals
1. **Admin report** — per day and per user: credit spend (what users were charged), provider cost (what Pixelforge charged us), profit. Provider cost must match `GET /v1/usage`.
2. **Balances** — per-user credit balance, a fake "Buy credits" button, and generation blocked when the user can't afford it.

### Evaluation
Correct money (exact cost, never twice, failures free) · Robustness (limits, overloads, failures, duplicate/out-of-order webhooks) · Product sense/UX (clear costs, state, errors) · Code quality.

### Deliverables
Public GitHub repo · deployed URL · Pixelforge account email.

## Pixelforge API reference (condensed)

- Base URL `https://pixelforge.promptedit.dev/v1`, JSON, `Authorization: Bearer <key>`.
- Money is an exact decimal string with 9 places (`"0.046125000"`). **Never use floats.**
- IDs: `gen_…` generations, `evt_…` events. Timestamps are ISO-8601 UTC.

### Errors — `{"error": {"type","code","message","param?","errors?"}}`; branch on `code`
| code | HTTP | Meaning / handling |
|---|---|---|
| invalid_api_key | 401 | config problem |
| validation_failed | 422 | unknown params are rejected |
| content_policy_violation | 400 | prompt rejected, nothing created |
| not_found | 404 | |
| idempotency_key_reused | 409 | same key, different params |
| rate_limit_exceeded | 429 | 60 req/min per account; honor `Retry-After` |
| concurrency_limit_exceeded | 429 | max 3 queued+processing per account; `Retry-After: 5` |
| daily_limit_exceeded | 429 | 500 generations per UTC day |
| overloaded | 503 | `Retry-After: 2`; nothing created; retry with the same Idempotency-Key |
| date_range_too_long | 422 | usage range > 92 days |

Limits are **per account across all keys** (the local and production keys share them). Responses include `X-RateLimit-Limit` and `X-RateLimit-Remaining`.

### Idempotency
`Idempotency-Key` header on POST (≤255 chars). The same key with the same params returns the current generation with 202 and `Idempotent-Replayed: true`, bills nothing and doesn't count against limits. The same key with different params returns 409.

### `POST /generations` → 202, generation `status: queued`
Body: `prompt` (required, ≤4000 chars), `size` (`1024x1024` default, `1536x1024`, `1024x1536`), `quality` (`low`, `medium` default, `high`), `reference_images` (≤4 http(s) URLs), `metadata` (≤20 string→string, values ≤500 chars). **Any other key → 422.**

### `GET /generations/{id}`, `GET /generations?limit&status&cursor`

### Generation object
`id, object, status (queued|processing|succeeded|failed), prompt, size, quality, reference_images, metadata, image_url, image_url_expires_at, usage (null until succeeded: input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens, total_tokens), rates (USD per 1M tokens, fixed at creation), error ({code,message} | null; codes generation_failed, internal_error), created_at, started_at, completed_at`.
Lifecycle: queued → processing → succeeded|failed (final). A few seconds queued, then 5–30 s processing.

### Pricing — `GET /pricing` (no auth)
Rates per 1M tokens: input_text 5.00 · input_image 10.00 · output 40.00 · reasoning 12.50.
- Text tokens ≈ ceil(unicode chars / 4), with actual within ±15%.
- 765 tokens per reference image (exact).
- Output tokens (exact): low 280/420/420 · medium 1100/1650/1650 · high 4300/6450/6450 (1024x1024 / 1536x1024 / 1024x1536).
- Reasoning tokens: low 0–500 · medium 200–2000 · high 1000–8000.
- Response `tokens` block: `characters_per_text_token, text_token_variance, per_reference_image, output{quality{size}}, reasoning{quality{min,max}}`.
- Only succeeded generations are billed, at the `rates` on the generation. Cost = Σ tokens × rate / 1e6, exact.
- Worked max example: high, 1536x1024, 400 chars, 2 refs → **0.373875000**.

### Usage — `GET /usage?start_date&end_date` (YYYY-MM-DD, inclusive, ≤92 days, default last 30)
Per **API key**, per **UTC day the generation completed**. `data[]: {date, generations, input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens, cost_usd}` plus `total`. Days with zero usage are included.

### Webhooks
- One URL and secret (`whsec_…`) per API key. Events: `generation.processing`, `generation.succeeded`, `generation.failed`, `ping`.
- Body `{id: evt_…, object: "event", type, created_at, data: {object: <generation>}}`.
- Headers: `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`, `Pixelforge-Signature: t=<unix>,v1=<hex>`.
- Signature = hex HMAC-SHA256(`"{t}.{raw body}"`, secret). Compare in constant time; reject when |now − t| > 300 s.
- A 2xx within 10 s counts as delivered. Otherwise retries after 10s, 30s, 2m, 10m, 15m (6 attempts). Redirects are not followed.
- At-least-once delivery, possibly out of order, possibly **before the POST returns**, and not guaranteed, so poll as a fallback.

### Images
`image_url` is signed and expires 24 h after completion. Re-fetching gives the same expiry. Store the image ourselves to keep it.

### Test keywords (in prompt, case-insensitive)
`[blocked]` 400 · `[overloaded]` 503 · `[fail]` fails while processing · `[succeed]` never fails randomly · `[slow]` 60–90 s · `[duplicate]` every webhook sent twice.
