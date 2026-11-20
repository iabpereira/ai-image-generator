# Phase 1 — Core Generations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Signed-in users generate images through Pixelforge, see the cost before submitting, follow live status in a history page, and are charged exactly once (cost + 25%), only for successful generations. The app is deployed publicly in a single Docker container.

**Architecture:** A local `generations` row is created *before* calling Pixelforge, and its UUID is used both as the `Idempotency-Key` and as `metadata.generation_uuid`. A queued job submits it and handles rate, concurrency and overload limits. Every status change goes through **one** state machine (`GenerationLifecycle::apply`), whether it comes from the POST response, a webhook or the polling fallback. The state machine uses an atomic compare-and-set `UPDATE … WHERE status IN (allowed predecessors)`, so duplicate or out-of-order events can't move a generation backwards or charge it twice. Money is stored as integer **nanodollars** (1 USD = 10⁹), which is the exact precision Pixelforge reports.

**Tech Stack:** Laravel 13 (PHP 8.5), Inertia v3 + Vue 3 + TypeScript, Wayfinder, Fortify (existing auth), Pest 5, SQLite (WAL) + database queue and cache, FrankenPHP + supervisord in a single container.

**Spec:** `docs/superpowers/specs/2026-11-20-pixelforge-assessment.md`

## Global Constraints

- Never use floats for money. Store integer nanodollars in `bigInteger` columns and exchange decimal strings with 9 places over the wire.
- Markup: user charge = provider cost × 125 / 100, **rounded up** to the next nanodollar. Display with 4 decimals (`$0.0582`), with the exact 9-decimal value in a `title` tooltip.
- Failed generations: `provider_cost = 0`, `charged_amount = 0`.
- `succeeded` and `failed` are final. Status rank: pending 0 < queued 1 < processing 2 < succeeded = failed 3. Only strictly increasing transitions are allowed.
- Never send unknown keys to `POST /v1/generations`. Pixelforge returns 422 for them.
- Follow sibling conventions: `#[Fillable]` attributes on models, `casts()` method, PHPDoc array shapes, curly braces always, explicit return types.
- Create files with `php artisan make:* --no-interaction`. Run `vendor/bin/pint --dirty --format agent` before each commit that touches PHP.
- Tests: Pest, `php artisan test --compact <path>`. Feature tests use `RefreshDatabase` (already configured in `tests/Pest.php`). Use `Http::preventStrayRequests()` in every test that touches the client.
- Before writing Inertia/Vue code, activate the `inertia-vue-development` skill and use `search-docs` for `usePoll` and `useForm` in Inertia v3.
- New app routes use the `auth` middleware only, **not** `verified`. Production mail is `log`, so reviewers couldn't verify their email (see Task 0).

## File Map

| File | Responsibility |
|---|---|
| `app/Support/Money.php` | Exact USD value object (nanodollars) |
| `app/Casts/MoneyCast.php` | Eloquent cast between int column and `Money` |
| `app/Enums/GenerationStatus.php`, `GenerationSize.php`, `GenerationQuality.php` | Domain enums + transition rules |
| `app/Services/Pixelforge/Pricing.php` | Pricing table value object (from `GET /v1/pricing`) |
| `app/Services/Pixelforge/CostCalculator.php` | Estimate, actual cost, markup |
| `app/Services/Pixelforge/CostEstimate.php` | Estimate result DTO |
| `app/Services/Pixelforge/PixelforgeClient.php` | HTTP client (create, retrieve, pricing) |
| `app/Services/Pixelforge/PixelforgeException.php` | Typed API error with retry semantics |
| `app/Models/Generation.php` + migration + factory | Local generation record |
| `app/Services/Generations/GenerationLifecycle.php` | **The only place that changes generation status or money** |
| `app/Jobs/SubmitGeneration.php` | Submit to Pixelforge with retry/backoff |
| `app/Models/PixelforgeWebhookEvent.php` + migration | Webhook dedupe/audit log |
| `app/Http/Middleware/VerifyPixelforgeSignature.php` | HMAC verification |
| `app/Http/Controllers/PixelforgeWebhookController.php` | Store event, enqueue processing, ack fast |
| `app/Jobs/ProcessPixelforgeWebhook.php` | Apply event snapshot via lifecycle |
| `app/Console/Commands/SyncPendingGenerations.php` | Polling fallback for lost webhooks |
| `app/Jobs/StoreGenerationImage.php` | Download image before the URL expires |
| `app/Http/Controllers/GenerationController.php`, `app/Http/Requests/StoreGenerationRequest.php`, `app/Http/Resources/GenerationResource.php` | Web endpoints |
| `resources/js/lib/pricing.ts`, `resources/js/lib/money.ts` | Client-side estimate preview + formatting |
| `resources/js/pages/generations/Create.vue`, `Index.vue`, `resources/js/components/GenerationCard.vue`, `GenerationStatusBadge.vue` | UI |
| `Dockerfile`, `docker/supervisord.conf`, `docker/entrypoint.sh`, `compose.yaml`, `.dockerignore` | Single-container deploy |

---

### Task 0: Repository bootstrap and configuration

**Files:**
- Modify: `config/services.php`, `config/database.php` (sqlite block), `config/fortify.php:76`, `.env.example`, `bootstrap/app.php`
- Create: git repository

- [ ] **Step 1: Initialize git**

```bash
git init && git add -A && git commit -m "chore: laravel vue starter kit"
```

- [ ] **Step 2: Add Pixelforge config** — append to the array in `config/services.php`:

```php
    'pixelforge' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'api_key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
        'markup_percent' => (int) env('PIXELFORGE_MARKUP_PERCENT', 25),
        'max_concurrent_generations' => 3,
    ],
```

Append to `.env.example`:

```dotenv
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
PIXELFORGE_MARKUP_PERCENT=25
```

- [ ] **Step 3: Make SQLite safe for web + worker + scheduler concurrency.** In `config/database.php` sqlite block, replace the null/DEFERRED values:

```php
            'busy_timeout' => env('DB_BUSY_TIMEOUT', 5000),
            'journal_mode' => env('DB_JOURNAL_MODE', 'wal'),
            'synchronous' => env('DB_SYNCHRONOUS', 'normal'),
            'transaction_mode' => env('DB_TRANSACTION_MODE', 'IMMEDIATE'),
```

`IMMEDIATE` takes the write lock at `BEGIN`, so two transactions never deadlock trying to upgrade a read lock.

- [ ] **Step 4: Exempt the webhook path from CSRF.** In `bootstrap/app.php`, inside `withMiddleware`:

```php
        $middleware->validateCsrfTokens(except: ['webhooks/pixelforge']);
```

- [ ] **Step 5: Land users on the generate page.** In `config/fortify.php:76` set `'home' => '/generations/create',`.

- [ ] **Step 6: Verify nothing broke**

Run: `php artisan test --compact`
Expected: all existing tests PASS. If `RegistrationTest`/`AuthenticationTest` assert `route('dashboard')` as the redirect, update the assertion to `'/generations/create'`. That's a behavior change we intend, not a test deletion.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "chore: pixelforge config, sqlite wal, webhook csrf exemption"
```

---

### Task 1: `Money` value object and cast

**Files:**
- Create: `app/Support/Money.php`, `app/Casts/MoneyCast.php`
- Test: `tests/Unit/MoneyTest.php`

**Interfaces — Produces:**
- `Money::fromNanos(int): Money`, `Money::zero(): Money`, `Money::fromDecimalString(string): Money`, `Money::fromDollars(int): Money`
- `->nanos: int`, `->toDecimalString(): string` (9 places), `->plus(Money)`, `->minus(Money)`, `->isNegative(): bool`, `->isGreaterThan(Money): bool`, `->multiplyRoundingUp(int $numerator, int $denominator): Money`, `jsonSerialize(): string`
- `MoneyCast` for `bigInteger` columns (nullable aware)

- [ ] **Step 1: Write the failing test** — `php artisan make:test --pest --unit MoneyTest`, then:

```php
<?php

use App\Support\Money;

it('parses pixelforge decimal strings exactly', function (string $input, int $nanos) {
    expect(Money::fromDecimalString($input)->nanos)->toBe($nanos);
})->with([
    ['0.046125000', 46_125_000],
    ['0.373875000', 373_875_000],
    ['12.5', 12_500_000_000],
    ['0', 0],
    ['-0.000000001', -1],
]);

it('formats as a 9-decimal string', function () {
    expect(Money::fromNanos(305_510_000)->toDecimalString())->toBe('0.305510000')
        ->and(Money::fromNanos(-1)->toDecimalString())->toBe('-0.000000001')
        ->and(Money::fromDollars(5)->toDecimalString())->toBe('5.000000000');
});

it('rejects malformed amounts', function (string $input) {
    Money::fromDecimalString($input);
})->throws(InvalidArgumentException::class)->with(['abc', '1.0000000001', '1e-9', '']);

it('multiplies rounding up to the next nanodollar', function () {
    expect(Money::fromNanos(305_510_000)->multiplyRoundingUp(125, 100)->nanos)->toBe(381_887_500)
        ->and(Money::fromNanos(1)->multiplyRoundingUp(125, 100)->nanos)->toBe(2)
        ->and(Money::fromNanos(4)->multiplyRoundingUp(125, 100)->nanos)->toBe(5);
});

it('adds, subtracts and compares', function () {
    $a = Money::fromNanos(10);
    $b = Money::fromNanos(3);

    expect($a->plus($b)->nanos)->toBe(13)
        ->and($b->minus($a)->isNegative())->toBeTrue()
        ->and($a->isGreaterThan($b))->toBeTrue();
});
```

- [ ] **Step 2: Run it and check it fails** — `php artisan test --compact tests/Unit/MoneyTest.php`. Expected: FAIL, class `App\Support\Money` not found.

- [ ] **Step 3: Implement** `app/Support/Money.php` (`php artisan make:class Support/Money --no-interaction`, then replace):

```php
<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An exact USD amount held as integer nanodollars (1 USD = 1_000_000_000),
 * the precision Pixelforge reports costs in.
 */
final readonly class Money implements JsonSerializable
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private function __construct(public int $nanos) {}

    public static function fromNanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromDollars(int $dollars): self
    {
        return new self($dollars * self::NANOS_PER_DOLLAR);
    }

    public static function fromDecimalString(string $amount): self
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,9}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $nanos = (int) $matches[2] * self::NANOS_PER_DOLLAR
            + (int) str_pad($matches[3] ?? '', 9, '0');

        return new self($matches[1] === '-' ? -$nanos : $nanos);
    }

    public function toDecimalString(): string
    {
        $absolute = abs($this->nanos);

        return ($this->nanos < 0 ? '-' : '')
            .intdiv($absolute, self::NANOS_PER_DOLLAR)
            .'.'
            .str_pad((string) ($absolute % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    public function isNegative(): bool
    {
        return $this->nanos < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    /**
     * Multiply by a ratio, rounding any fraction of a nanodollar up.
     */
    public function multiplyRoundingUp(int $numerator, int $denominator): self
    {
        $product = $this->nanos * $numerator;
        $quotient = intdiv($product, $denominator);

        if ($product % $denominator > 0) {
            $quotient++;
        }

        return new self($quotient);
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }
}
```

`app/Casts/MoneyCast.php` (`php artisan make:cast MoneyCast --no-interaction`):

```php
<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<Money|null, Money|int|null>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::fromNanos((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value instanceof Money ? $value->nanos : ($value === null ? null : (int) $value);
    }
}
```

- [ ] **Step 4: Run the test and check it passes** — `php artisan test --compact tests/Unit/MoneyTest.php`. Expected: PASS.

- [ ] **Step 5: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: exact Money value object in nanodollars"`

---

### Task 2: Enums, `Pricing` and `CostCalculator`

**Files:**
- Create: `app/Enums/GenerationStatus.php`, `app/Enums/GenerationSize.php`, `app/Enums/GenerationQuality.php`, `app/Services/Pixelforge/Pricing.php`, `app/Services/Pixelforge/CostEstimate.php`, `app/Services/Pixelforge/CostCalculator.php`
- Test: `tests/Unit/GenerationStatusTest.php`, `tests/Unit/CostCalculatorTest.php`

**Interfaces — Produces:**
- `GenerationStatus::{Pending,Queued,Processing,Succeeded,Failed}`, `->isFinal(): bool`, `->canTransitionTo(GenerationStatus): bool`, `GenerationStatus::predecessorsOf(GenerationStatus): list<GenerationStatus>`
- `GenerationSize::{Square='1024x1024', Landscape='1536x1024', Portrait='1024x1536'}`, `GenerationQuality::{Low,Medium,High}`
- `Pricing::fromApi(array $payload): Pricing`, `->toArray(): array` (frontend shape), public props `rates`, `charactersPerTextToken`, `textTokenVariancePercent`, `tokensPerReferenceImage`, `outputTokens`, `reasoningTokens`
- `CostCalculator::__construct(int $markupPercent)`, `->estimate(Pricing, string $prompt, GenerationSize, GenerationQuality, int $referenceImageCount): CostEstimate`, `->providerCost(array $usage, array $rates): Money`, `->charge(Money $providerCost): Money`
- `CostEstimate` readonly: `Money $typicalCharge`, `Money $maximumCharge`, `Money $maximumProviderCost`

- [ ] **Step 1: Write failing tests**

`tests/Unit/GenerationStatusTest.php`:

```php
<?php

use App\Enums\GenerationStatus;

it('only moves forward', function (GenerationStatus $from, GenerationStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    [GenerationStatus::Pending, GenerationStatus::Queued, true],
    [GenerationStatus::Pending, GenerationStatus::Succeeded, true],
    [GenerationStatus::Queued, GenerationStatus::Processing, true],
    [GenerationStatus::Processing, GenerationStatus::Queued, false],
    [GenerationStatus::Processing, GenerationStatus::Processing, false],
    [GenerationStatus::Succeeded, GenerationStatus::Failed, false],
    [GenerationStatus::Failed, GenerationStatus::Succeeded, false],
    [GenerationStatus::Succeeded, GenerationStatus::Processing, false],
]);

it('lists the statuses allowed to move into a status', function () {
    expect(GenerationStatus::predecessorsOf(GenerationStatus::Processing))
        ->toBe([GenerationStatus::Pending, GenerationStatus::Queued]);
});
```

`tests/Unit/CostCalculatorTest.php`:

```php
<?php

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\Pricing;

function pixelforgePricing(): Pricing
{
    return Pricing::fromApi([
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ]);
}

it('matches the documented maximum-cost worked example', function () {
    $estimate = (new CostCalculator(25))->estimate(
        pixelforgePricing(),
        str_repeat('a', 400),
        GenerationSize::Landscape,
        GenerationQuality::High,
        2,
    );

    expect($estimate->maximumProviderCost->toDecimalString())->toBe('0.373875000')
        ->and($estimate->maximumCharge->toDecimalString())->toBe('0.467343750');
});

it('estimates the typical charge with mid-range reasoning and nominal text tokens', function () {
    // text 100*5000=500_000, output 1100*40_000=44_000_000, reasoning 1100*12_500=13_750_000
    $estimate = (new CostCalculator(25))->estimate(
        pixelforgePricing(), str_repeat('a', 400), GenerationSize::Square, GenerationQuality::Medium, 0,
    );

    expect($estimate->typicalCharge->nanos)->toBe(72_812_500);
});

it('counts unicode characters, not bytes', function () {
    $ascii = (new CostCalculator(25))->estimate(pixelforgePricing(), 'aaaa', GenerationSize::Square, GenerationQuality::Low, 0);
    $emoji = (new CostCalculator(25))->estimate(pixelforgePricing(), '🎨🎨🎨🎨', GenerationSize::Square, GenerationQuality::Low, 0);

    expect($emoji->typicalCharge)->toEqual($ascii->typicalCharge);
});

it('computes the actual provider cost from usage and the generation rates', function () {
    $cost = (new CostCalculator(25))->providerCost(
        ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411],
        ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    );

    expect($cost->toDecimalString())->toBe('0.305510000');
});

it('applies the markup rounding up', function () {
    $calculator = new CostCalculator(25);

    expect($calculator->charge(\App\Support\Money::fromNanos(305_510_000))->nanos)->toBe(381_887_500)
        ->and($calculator->charge(\App\Support\Money::fromNanos(1))->nanos)->toBe(2);
});
```

- [ ] **Step 2: Run them and check they fail** — `php artisan test --compact tests/Unit/GenerationStatusTest.php tests/Unit/CostCalculatorTest.php`. Expected: FAIL, classes missing.

- [ ] **Step 3: Implement the enums** (`php artisan make:enum Enums/GenerationStatus --string --no-interaction`, same for size and quality; check `--help` for the exact flag name):

```php
<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Stored locally, not yet accepted by Pixelforge. */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function canTransitionTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    /**
     * @return list<self>
     */
    public static function predecessorsOf(self $next): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status->canTransitionTo($next),
        ));
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
```

```php
<?php

namespace App\Enums;

enum GenerationSize: string
{
    case Square = '1024x1024';
    case Landscape = '1536x1024';
    case Portrait = '1024x1536';
}
```

```php
<?php

namespace App\Enums;

enum GenerationQuality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
```

- [ ] **Step 4: Implement `Pricing`, `CostEstimate`, `CostCalculator`**

`app/Services/Pixelforge/Pricing.php`:

```php
<?php

namespace App\Services\Pixelforge;

/**
 * Pixelforge's published price list (GET /v1/pricing).
 */
final readonly class Pricing
{
    /**
     * @param  array<string, string>  $rates  USD per 1M tokens, keyed by token kind
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public int $textTokenVariancePercent,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array{rates: array<string, string>, tokens: array{characters_per_text_token: int, text_token_variance: float|int, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}}  $payload
     */
    public static function fromApi(array $payload): self
    {
        $tokens = $payload['tokens'];

        return new self(
            rates: $payload['rates'],
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVariancePercent: (int) round($tokens['text_token_variance'] * 100),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /**
     * @return array{rates: array<string, string>, characters_per_text_token: int, text_token_variance_percent: int, tokens_per_reference_image: int, output_tokens: array<string, array<string, int>>, reasoning_tokens: array<string, array{min: int, max: int}>}
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance_percent' => $this->textTokenVariancePercent,
            'tokens_per_reference_image' => $this->tokensPerReferenceImage,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
```

`app/Services/Pixelforge/CostEstimate.php`:

```php
<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

final readonly class CostEstimate
{
    public function __construct(
        public Money $typicalCharge,
        public Money $maximumCharge,
        public Money $maximumProviderCost,
    ) {}
}
```

`app/Services/Pixelforge/CostCalculator.php`:

```php
<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Support\Money;

final readonly class CostCalculator
{
    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(private int $markupPercent) {}

    public function estimate(
        Pricing $pricing,
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        int $referenceImageCount,
    ): CostEstimate {
        $nominalTextTokens = (int) ceil(mb_strlen($prompt) / $pricing->charactersPerTextToken);
        $maximumTextTokens = intdiv($nominalTextTokens * (100 + $pricing->textTokenVariancePercent) + 99, 100);
        $reasoning = $pricing->reasoningTokens[$quality->value];

        $fixedTokens = [
            'input_image_tokens' => $referenceImageCount * $pricing->tokensPerReferenceImage,
            'output_tokens' => $pricing->outputTokens[$quality->value][$size->value],
        ];

        $typicalProviderCost = $this->providerCost($fixedTokens + [
            'input_text_tokens' => $nominalTextTokens,
            'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
        ], $pricing->rates);

        $maximumProviderCost = $this->providerCost($fixedTokens + [
            'input_text_tokens' => $maximumTextTokens,
            'reasoning_tokens' => $reasoning['max'],
        ], $pricing->rates);

        return new CostEstimate(
            typicalCharge: $this->charge($typicalProviderCost),
            maximumCharge: $this->charge($maximumProviderCost),
            maximumProviderCost: $maximumProviderCost,
        );
    }

    /**
     * Exact Pixelforge cost: Σ tokens × rate / 1M. Keys not present in $rates
     * (e.g. total_tokens) are ignored.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates  USD per 1M tokens
     */
    public function providerCost(array $usage, array $rates): Money
    {
        $nanoTokenRate = 0;

        foreach ($rates as $tokenKind => $rate) {
            $nanoTokenRate += ($usage[$tokenKind] ?? 0) * Money::fromDecimalString($rate)->nanos;
        }

        return Money::fromNanos($nanoTokenRate)->multiplyRoundingUp(1, self::TOKENS_PER_RATE_UNIT);
    }

    public function charge(Money $providerCost): Money
    {
        return $providerCost->multiplyRoundingUp(100 + $this->markupPercent, 100);
    }
}
```

Bind it in `app/Providers/AppServiceProvider.php` `register()`:

```php
        $this->app->singleton(CostCalculator::class, fn (): CostCalculator => new CostCalculator(
            (int) config('services.pixelforge.markup_percent'),
        ));
```

- [ ] **Step 5: Run the tests and check they pass** — same command as Step 2. Expected: PASS.

- [ ] **Step 6: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: generation enums and exact cost calculator"`

---

### Task 3: `PixelforgeClient` and `PixelforgeException`

**Files:**
- Create: `app/Services/Pixelforge/PixelforgeClient.php`, `app/Services/Pixelforge/PixelforgeException.php`
- Modify: `app/Providers/AppServiceProvider.php` (binding)
- Test: `tests/Feature/PixelforgeClientTest.php`

**Interfaces — Produces:**
- `PixelforgeClient::createGeneration(array $payload, string $idempotencyKey): array` (generation object)
- `PixelforgeClient::retrieveGeneration(string $providerId): array`
- `PixelforgeClient::pricing(): Pricing` (cached via `Cache::flexible`, fresh 1h / stale up to 24h)
- `PixelforgeException`: public `int $status`, `string $errorCode`, `?int $retryAfter`; `isThrottled(): bool` (rate/concurrency limit), `isTransient(): bool` (overloaded, 5xx, connection), `userMessage(): string`; `static fromResponse(Response)`, `static connectionFailed(ConnectionException)`

- [ ] **Step 1: Write the failing test** — `php artisan make:test --pest PixelforgeClientTest`:

```php
<?php

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'pk_test', 'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1']);
    Http::preventStrayRequests();
});

it('creates a generation with bearer auth and idempotency key', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

    $generation = app(PixelforgeClient::class)->createGeneration(['prompt' => 'a cat'], 'uuid-1');

    expect($generation['id'])->toBe('gen_1');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer pk_test')
        && $request->hasHeader('Idempotency-Key', 'uuid-1')
        && $request['prompt'] === 'a cat');
});

it('maps api errors to typed exceptions', function (int $status, string $code, array $headers, bool $throttled, bool $transient) {
    Http::fake(['*' => Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => 'nope']], $status, $headers)]);

    try {
        app(PixelforgeClient::class)->createGeneration(['prompt' => 'a'], 'k');
        $this->fail('Expected exception');
    } catch (PixelforgeException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->status)->toBe($status)
            ->and($exception->isThrottled())->toBe($throttled)
            ->and($exception->isTransient())->toBe($transient)
            ->and($exception->retryAfter)->toBe(isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null);
    }
})->with([
    'rate limit' => [429, 'rate_limit_exceeded', ['Retry-After' => '12'], true, false],
    'concurrency' => [429, 'concurrency_limit_exceeded', ['Retry-After' => '5'], true, false],
    'daily' => [429, 'daily_limit_exceeded', [], false, false],
    'overloaded' => [503, 'overloaded', ['Retry-After' => '2'], false, true],
    'content policy' => [400, 'content_policy_violation', [], false, false],
    'validation' => [422, 'validation_failed', [], false, false],
]);

it('treats connection failures as transient', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    expect(fn () => app(PixelforgeClient::class)->retrieveGeneration('gen_1'))
        ->toThrow(fn (PixelforgeException $e) => expect($e->isTransient())->toBeTrue());
});

it('caches pricing', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response([
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => ['characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
            'output' => ['low' => ['1024x1024' => 280]], 'reasoning' => ['low' => ['min' => 0, 'max' => 500]]],
    ])]);

    app(PixelforgeClient::class)->pricing();
    $pricing = app(PixelforgeClient::class)->pricing();

    expect($pricing->textTokenVariancePercent)->toBe(15);
    Http::assertSentCount(1);
});
```

- [ ] **Step 2: Run it and check it fails** — `php artisan test --compact tests/Feature/PixelforgeClientTest.php`.

- [ ] **Step 3: Implement**

`app/Services/Pixelforge/PixelforgeException.php`:

```php
<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

final class PixelforgeException extends RuntimeException
{
    private const array THROTTLE_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded'];

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            status: $response->status(),
            errorCode: (string) $response->json('error.code', 'http_'.$response->status()),
            message: (string) $response->json('error.message', 'Pixelforge request failed.'),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function connectionFailed(ConnectionException $exception): self
    {
        return new self(0, 'connection_failed', $exception->getMessage());
    }

    public function isThrottled(): bool
    {
        return in_array($this->errorCode, self::THROTTLE_CODES, true);
    }

    public function isTransient(): bool
    {
        return $this->errorCode === 'overloaded' || $this->status === 0 || $this->status >= 500;
    }

    /**
     * A message safe to show the end user.
     */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Your prompt was rejected by the content policy. You were not charged.',
            'daily_limit_exceeded' => 'The daily generation limit was reached. Please try again tomorrow. You were not charged.',
            'validation_failed' => 'Pixelforge rejected the request: '.$this->getMessage(),
            default => 'Pixelforge could not accept this generation. You were not charged.',
        };
    }
}
```

`app/Services/Pixelforge/PixelforgeClient.php`:

```php
<?php

namespace App\Services\Pixelforge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class PixelforgeClient
{
    public function __construct(private string $baseUrl, private string $apiKey) {}

    /**
     * @param  array{prompt: string, size?: string, quality?: string, reference_images?: list<string>, metadata?: array<string, string>}  $payload
     * @return array<string, mixed>
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        return $this->send(fn (): Response => $this->authenticated()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveGeneration(string $providerId): array
    {
        return $this->send(fn (): Response => $this->authenticated()->get("/generations/{$providerId}"));
    }

    public function pricing(): Pricing
    {
        /** @var array{rates: array<string, string>, tokens: array{characters_per_text_token: int, text_token_variance: float, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}} $payload */
        $payload = Cache::flexible('pixelforge.pricing', [3600, 86400], fn (): array => $this->send(
            fn (): Response => $this->baseRequest()->get('/pricing'),
        ));

        return Pricing::fromApi($payload);
    }

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    private function authenticated(): PendingRequest
    {
        return $this->baseRequest()->withToken($this->apiKey);
    }

    /**
     * @param  Closure(): Response  $request
     * @return array<string, mixed>
     */
    private function send(Closure $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw PixelforgeException::connectionFailed($exception);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }
}
```

Bind it in `AppServiceProvider::register()`:

```php
        $this->app->bind(PixelforgeClient::class, fn (): PixelforgeClient => new PixelforgeClient(
            (string) config('services.pixelforge.base_url'),
            (string) config('services.pixelforge.api_key'),
        ));
```

- [ ] **Step 4: Run the test and check it passes.** Expected: PASS.

- [ ] **Step 5: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: pixelforge http client with typed errors"`

---

### Task 4: `Generation` model, migration, factory

**Files:**
- Create: migration `create_generations_table`, `app/Models/Generation.php`, `database/factories/GenerationFactory.php` (`php artisan make:model Generation -mf --no-interaction`)
- Modify: `app/Models/User.php` (add `generations()` relation)
- Test: covered by Task 5 onward (the model has no behavior of its own beyond casts and `toProviderPayload`, which Task 6 tests)

**Interfaces — Produces:**
- Columns: `id, uuid (unique), user_id, provider_id (nullable unique), status, prompt, size, quality, reference_images (json), estimated_charge, maximum_charge, provider_cost (nullable), charged_amount (nullable)` (all money is bigInteger nanodollars), `rates (json null), usage (json null), image_url, image_url_expires_at, image_path, error_code, error_message, submission_attempts, submitted_at, started_at, completed_at, timestamps`
- `Generation::toProviderPayload(): array`, scope `occupyingProviderSlot()`, accessor `image_src: ?string`, `User::generations(): HasMany`
- Factory states: `pending()` (default), `queued()`, `processing()`, `succeeded()`, `failed()`

- [ ] **Step 1: Migration body**

```php
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->bigInteger('estimated_charge')->comment('nanodollars, typical charge shown before submit');
            $table->bigInteger('maximum_charge')->comment('nanodollars, worst-case charge');
            $table->bigInteger('provider_cost')->nullable()->comment('nanodollars Pixelforge billed us');
            $table->bigInteger('charged_amount')->nullable()->comment('nanodollars charged to the user');
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('submission_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'completed_at']);
            $table->index(['user_id', 'id']);
        });
```

- [ ] **Step 2: Model**

```php
<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Support\Money;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property GenerationSize $size
 * @property GenerationQuality $quality
 * @property list<string> $reference_images
 * @property Money $estimated_charge
 * @property Money $maximum_charge
 * @property Money|null $provider_cost
 * @property Money|null $charged_amount
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $image_path
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submission_attempts
 * @property Carbon|null $submitted_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string|null $image_src
 */
#[Fillable([
    'uuid', 'status', 'prompt', 'size', 'quality', 'reference_images',
    'estimated_charge', 'maximum_charge', 'image_path', 'submission_attempts',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => GenerationSize::class,
            'quality' => GenerationQuality::class,
            'reference_images' => 'array',
            'estimated_charge' => MoneyCast::class,
            'maximum_charge' => MoneyCast::class,
            'provider_cost' => MoneyCast::class,
            'charged_amount' => MoneyCast::class,
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generations Pixelforge counts against its 3-concurrent limit.
     *
     * @param  Builder<Generation>  $query
     */
    #[Scope]
    protected function occupyingProviderSlot(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing]);
    }

    /**
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array{generation_uuid: string}}
     */
    public function toProviderPayload(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'metadata' => ['generation_uuid' => $this->uuid],
        ];
    }

    /**
     * Our stored copy wins. Otherwise use Pixelforge's URL while it is still valid.
     *
     * @return Attribute<string|null, never>
     */
    protected function imageSrc(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->image_path !== null) {
                return Storage::disk('public')->url($this->image_path);
            }

            if ($this->image_url !== null && $this->image_url_expires_at?->isFuture()) {
                return $this->image_url;
            }

            return null;
        });
    }
}
```

Note: check whether the `#[Scope]` attribute exists in this Laravel version (`search-docs` "local scopes"). If it doesn't, fall back to `scopeOccupyingProviderSlot(Builder $query): void`.

In `User.php`, add:

```php
    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }
```

- [ ] **Step 3: Factory**

```php
<?php

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'estimated_charge' => Money::fromNanos(72_812_500),
            'maximum_charge' => Money::fromNanos(130_000_000),
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_'.Str::ulid(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (): array => ['status' => GenerationStatus::Processing]);
    }

    public function succeeded(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            'provider_cost' => Money::fromNanos(305_510_000),
            'charged_amount' => Money::fromNanos(381_887_500),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'provider_cost' => Money::zero(),
            'charged_amount' => Money::zero(),
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image.',
            'completed_at' => now(),
        ]);
    }
}
```

`provider_id`, `provider_cost`, `charged_amount` and `completed_at` are deliberately **not** fillable, because only `GenerationLifecycle` writes them, through query-builder updates. Factories bypass fillable (`Model::unguarded` during `make`), so the states above still work. Verify this with a quick test in Task 5.

- [ ] **Step 4: Migrate** — `php artisan migrate --no-interaction`. Expected: `create_generations_table` DONE.

- [ ] **Step 5: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: generation model, migration and factory"`

---

### Task 5: `GenerationLifecycle` — the state machine (core money correctness)

**Files:**
- Create: `app/Services/Generations/GenerationLifecycle.php`, `app/Jobs/StoreGenerationImage.php` (empty `handle()` for now; Task 10 fills it in)
- Test: `tests/Feature/GenerationLifecycleTest.php`

**Interfaces:**
- Consumes: `CostCalculator::providerCost`, `CostCalculator::charge`, `GenerationStatus::predecessorsOf`, `Generation`
- Produces: `GenerationLifecycle::apply(Generation $generation, array $snapshot): bool`. It takes a Pixelforge generation object and returns true only if this call changed the state. Also `GenerationLifecycle::fail(Generation $generation, string $errorCode, string $message): bool`, a local terminal failure with zero cost.
- Extension point for Phase 2: the private `afterTransition(Generation $generation, GenerationStatus $to)` hook runs **inside** the same DB transaction, after a successful compare-and-set.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Generations\GenerationLifecycle;
use Illuminate\Support\Facades\Queue;

/**
 * @return array<string, mixed>
 */
function providerSnapshot(Generation $generation, string $status, array $overrides = []): array
{
    return array_replace([
        'id' => $generation->provider_id ?? 'gen_abc',
        'object' => 'generation',
        'status' => $status,
        'metadata' => ['generation_uuid' => $generation->uuid],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => $status === 'succeeded'
            ? ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411]
            : null,
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/gen_abc.png?sig=1' : null,
        'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601String() : null,
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'No image'] : null,
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed']) ? now()->subSeconds(10)->toIso8601String() : null,
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? now()->toIso8601String() : null,
    ], $overrides);
}

beforeEach(fn () => Queue::fake());

it('records the exact cost with markup when a generation succeeds', function () {
    $generation = Generation::factory()->processing()->create();

    $changed = app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'succeeded'));

    $generation->refresh();
    expect($changed)->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost->toDecimalString())->toBe('0.305510000')
        ->and($generation->charged_amount->toDecimalString())->toBe('0.381887500')
        ->and($generation->usage['reasoning_tokens'])->toBe(3184)
        ->and($generation->image_url)->not->toBeNull();
    Queue::assertPushed(StoreGenerationImage::class);
});

it('charges nothing for failed generations', function () {
    $generation = Generation::factory()->processing()->create();

    app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'failed'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_amount->nanos)->toBe(0)
        ->and($generation->provider_cost->nanos)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed');
});

it('ignores a duplicate succeeded event', function () {
    $generation = Generation::factory()->processing()->create();
    $lifecycle = app(GenerationLifecycle::class);

    expect($lifecycle->apply($generation, providerSnapshot($generation, 'succeeded')))->toBeTrue()
        ->and($lifecycle->apply($generation, providerSnapshot($generation, 'succeeded')))->toBeFalse();
    Queue::assertPushed(StoreGenerationImage::class, 1);
});

it('never moves backwards when events arrive out of order', function () {
    $generation = Generation::factory()->processing()->create();
    $lifecycle = app(GenerationLifecycle::class);

    $lifecycle->apply($generation, providerSnapshot($generation, 'succeeded'));
    $changed = $lifecycle->apply($generation, providerSnapshot($generation, 'processing'));

    expect($changed)->toBeFalse()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('never flips a final status', function () {
    $generation = Generation::factory()->failed()->create();

    app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'succeeded'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_amount->nanos)->toBe(0);
});

it('accepts a webhook that arrives before the submit response stored the provider id', function () {
    $generation = Generation::factory()->create(); // pending, provider_id null

    app(GenerationLifecycle::class)->apply($generation, providerSnapshot($generation, 'processing', ['id' => 'gen_early']));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Processing)
        ->provider_id->toBe('gen_early');
});

it('fails locally without cost', function () {
    $generation = Generation::factory()->create();

    expect(app(GenerationLifecycle::class)->fail($generation, 'content_policy_violation', 'Rejected'))->toBeTrue();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->charged_amount->nanos->toBe(0)
        ->error_message->toBe('Rejected');
});
```

- [ ] **Step 2: Run it and check it fails** — `php artisan test --compact tests/Feature/GenerationLifecycleTest.php`.

- [ ] **Step 3: Implement** `app/Services/Generations/GenerationLifecycle.php`:

```php
<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Pixelforge\CostCalculator;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of generation status and money.
 *
 * Every transition is an atomic compare-and-set: the UPDATE only matches rows whose
 * current status may legally move to the new one. Duplicate or stale events therefore
 * match zero rows and change nothing, so a generation is charged at most once.
 */
final readonly class GenerationLifecycle
{
    public function __construct(private CostCalculator $costs) {}

    /**
     * @param  array<string, mixed>  $snapshot  A Pixelforge generation object
     */
    public function apply(Generation $generation, array $snapshot): bool
    {
        $target = GenerationStatus::from($snapshot['status']);

        $attributes = [
            'provider_id' => $snapshot['id'],
            'rates' => isset($snapshot['rates']) ? json_encode($snapshot['rates']) : null,
            'started_at' => $this->timestamp($snapshot['started_at'] ?? null),
            'completed_at' => $this->timestamp($snapshot['completed_at'] ?? null),
        ];

        if ($target === GenerationStatus::Succeeded) {
            $providerCost = $this->costs->providerCost($snapshot['usage'], $snapshot['rates']);

            $attributes += [
                'usage' => json_encode($snapshot['usage']),
                'provider_cost' => $providerCost->nanos,
                'charged_amount' => $this->costs->charge($providerCost)->nanos,
                'image_url' => $snapshot['image_url'],
                'image_url_expires_at' => $this->timestamp($snapshot['image_url_expires_at'] ?? null),
            ];
        }

        if ($target === GenerationStatus::Failed) {
            $attributes += [
                'provider_cost' => 0,
                'charged_amount' => 0,
                'error_code' => $snapshot['error']['code'] ?? 'generation_failed',
                'error_message' => $snapshot['error']['message'] ?? 'The generation failed. You were not charged.',
            ];
        }

        return $this->transition($generation, $target, array_filter($attributes, fn ($value): bool => $value !== null));
    }

    public function fail(Generation $generation, string $errorCode, string $message): bool
    {
        return $this->transition($generation, GenerationStatus::Failed, [
            'provider_cost' => 0,
            'charged_amount' => 0,
            'error_code' => $errorCode,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Generation $generation, GenerationStatus $target, array $attributes): bool
    {
        return DB::transaction(function () use ($generation, $target, $attributes): bool {
            $updated = Generation::query()
                ->whereKey($generation->getKey())
                ->whereIn('status', GenerationStatus::predecessorsOf($target))
                ->update(['status' => $target->value, ...$attributes]);

            if ($updated === 0) {
                return false;
            }

            $generation->refresh();
            $this->afterTransition($generation, $target);

            return true;
        });
    }

    private function afterTransition(Generation $generation, GenerationStatus $target): void
    {
        if ($target === GenerationStatus::Succeeded) {
            StoreGenerationImage::dispatch($generation)->afterCommit();
        }
    }

    private function timestamp(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
```

Note: `array_filter` drops nulls so a later snapshot never erases data an earlier one set. `whereIn` with enum cases works because the query builder binds backed enums by value. If PHPStan complains, map with `array_map(fn ($s) => $s->value, …)`.

Create the placeholder job: `php artisan make:job StoreGenerationImage --no-interaction`, with constructor `public function __construct(public Generation $generation) {}` and an empty `handle(): void`.

- [ ] **Step 4: Run the test and check it passes.** Expected: 7 PASS.

- [ ] **Step 5: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: generation lifecycle state machine with compare-and-set transitions"`

---

### Task 6: `SubmitGeneration` job (limits, overloads, idempotent retries)

**Files:**
- Create: `app/Jobs/SubmitGeneration.php`
- Modify: `app/Providers/AppServiceProvider.php` (`RateLimiter::for('pixelforge', …)` in `boot()`)
- Test: `tests/Feature/SubmitGenerationTest.php`

**Interfaces:**
- Consumes: `PixelforgeClient::createGeneration`, `PixelforgeException`, `GenerationLifecycle::apply|fail`, `Generation::toProviderPayload`, scope `occupyingProviderSlot`
- Produces: `SubmitGeneration::dispatch(Generation $generation)`

Retry policy, as one table, matching the docs:

| Outcome | Action |
|---|---|
| 202 (incl. `Idempotent-Replayed`) | store `provider_id`, `apply()` snapshot |
| local in-flight ≥ 3 | `release(5)`, no API call |
| 429 `rate_limit_exceeded` / `concurrency_limit_exceeded` | `release(Retry-After ?? 5)` (waiting, not a failure) |
| 503 `overloaded`, 5xx, connection error | `submission_attempts++`, `release(min(60, max(Retry-After, 2^attempts)))`, and after 5 attempts `fail('submission_failed')` |
| 400 / 401 / 409 / 422 / `daily_limit_exceeded` | `fail(code, userMessage())` immediately (nothing was created) |
| deadline (`retryUntil` = created_at + 30 min) passes | `failed()` hook → `fail('submission_timeout')` |

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([\App\Jobs\StoreGenerationImage::class]);
    Http::preventStrayRequests();
});

function runSubmit(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('submits with the generation uuid as idempotency key and metadata', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued', 'metadata' => []], 202)]);

    runSubmit($generation)->assertNotReleased();

    expect($generation->refresh())->status->toBe(GenerationStatus::Queued)->provider_id->toBe('gen_1');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->uuid)
        && $request['metadata'] === ['generation_uuid' => $generation->uuid]
        && ! array_key_exists('user_id', $request->data()));
});

it('waits without calling the api when three generations are in flight', function () {
    Generation::factory()->count(3)->processing()->create();
    $generation = Generation::factory()->create();

    runSubmit($generation)->assertReleased(delay: 5);
    Http::assertNothingSent();
});

it('honours retry-after on throttling', function (string $code) {
    $generation = Generation::factory()->create();
    Http::fake(['*' => Http::response(['error' => ['code' => $code, 'message' => 'slow down']], 429, ['Retry-After' => '7'])]);

    runSubmit($generation)->assertReleased(delay: 7);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
})->with(['rate_limit_exceeded', 'concurrency_limit_exceeded']);

it('backs off on overload and gives up after five attempts', function () {
    $generation = Generation::factory()->create(['submission_attempts' => 4]);
    Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503, ['Retry-After' => '2'])]);

    runSubmit($generation)->assertNotReleased();

    expect($generation->refresh())->status->toBe(GenerationStatus::Failed)->error_code->toBe('submission_failed')
        ->and($generation->charged_amount->nanos)->toBe(0);
});

it('releases with exponential backoff on the first overloads', function () {
    $generation = Generation::factory()->create(['submission_attempts' => 2]);
    Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503, ['Retry-After' => '2'])]);

    runSubmit($generation)->assertReleased(delay: 8);
    expect($generation->refresh()->submission_attempts)->toBe(3);
});

it('fails immediately on permanent rejections', function (int $status, string $code) {
    $generation = Generation::factory()->create();
    Http::fake(['*' => Http::response(['error' => ['code' => $code, 'message' => 'no']], $status)]);

    runSubmit($generation)->assertNotReleased();
    expect($generation->refresh())->status->toBe(GenerationStatus::Failed)->error_code->toBe($code);
})->with([[400, 'content_policy_violation'], [429, 'daily_limit_exceeded'], [422, 'validation_failed']]);

it('does nothing if a webhook already advanced the generation', function () {
    $generation = Generation::factory()->processing()->create();

    runSubmit($generation)->assertNotReleased();
    Http::assertNothingSent();
});

it('marks the generation failed when the job finally fails', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('deadline'));

    expect($generation->refresh())->status->toBe(GenerationStatus::Failed)->error_code->toBe('submission_timeout');
});
```

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement** (`php artisan make:job SubmitGeneration --no-interaction`):

```php
<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationLifecycle;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Unlimited releases, bounded by retryUntil(). */
    public int $tries = 0;

    public int $maxExceptions = 3;

    private const int MAX_TRANSIENT_ATTEMPTS = 5;

    private const int MAX_BACKOFF_SECONDS = 60;

    private const int SLOT_WAIT_SECONDS = 5;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(30);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('pixelforge')];
    }

    public function handle(PixelforgeClient $client, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        if (Generation::query()->occupyingProviderSlot()->count() >= (int) config('services.pixelforge.max_concurrent_generations')) {
            $this->release(self::SLOT_WAIT_SECONDS);

            return;
        }

        try {
            $snapshot = $client->createGeneration($generation->toProviderPayload(), $generation->uuid);
        } catch (PixelforgeException $exception) {
            $this->handleRejection($generation, $exception, $lifecycle);

            return;
        }

        Generation::query()->whereKey($generation->id)->whereNull('provider_id')
            ->update(['provider_id' => $snapshot['id'], 'submitted_at' => now()]);

        $lifecycle->apply($generation, $snapshot);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail(
            $this->generation,
            'submission_timeout',
            'Pixelforge stayed busy for too long, so we stopped trying. You were not charged.',
        );
    }

    private function handleRejection(Generation $generation, PixelforgeException $exception, GenerationLifecycle $lifecycle): void
    {
        if ($exception->isThrottled()) {
            $this->release($exception->retryAfter ?? self::SLOT_WAIT_SECONDS);

            return;
        }

        if (! $exception->isTransient()) {
            $lifecycle->fail($generation, $exception->errorCode, $exception->userMessage());

            return;
        }

        $attempts = $generation->submission_attempts + 1;
        $generation->update(['submission_attempts' => $attempts]);

        if ($attempts >= self::MAX_TRANSIENT_ATTEMPTS) {
            $lifecycle->fail($generation, 'submission_failed', 'Pixelforge is unavailable right now. You were not charged.');

            return;
        }

        $this->release(min(self::MAX_BACKOFF_SECONDS, max($exception->retryAfter ?? 0, 2 ** $attempts)));
    }
}
```

In `AppServiceProvider::boot()`, define the limiter. We leave 10 of the account's 60 requests per minute for polling and for the local key:

```php
        RateLimiter::for('pixelforge', fn (): Limit => Limit::perMinute(50));
```

(Imports: `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Support\Facades\RateLimiter`.)

- [ ] **Step 4: Run the test and check it passes.** If `withFakeQueueInteractions()` doesn't intercept `release()` when the job is called through `app()->call`, call `$job->handle(app(PixelforgeClient::class), app(GenerationLifecycle::class))` directly instead.

- [ ] **Step 5: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: submit generation job with throttling and idempotent retries"`

---

### Task 7: Generate and History endpoints

**Files:**
- Create: `app/Http/Controllers/GenerationController.php`, `app/Http/Requests/StoreGenerationRequest.php`, `app/Http/Resources/GenerationResource.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/GenerationControllerTest.php`

**Interfaces:**
- Consumes: `PixelforgeClient::pricing`, `CostCalculator::estimate`, `SubmitGeneration`
- Produces: routes `generations.index` (GET `/generations`), `generations.create` (GET `/generations/create`), `generations.store` (POST `/generations`). Inertia pages `generations/Index` (prop `generations`: paginated `GenerationResource`) and `generations/Create` (props `pricing`: `Pricing::toArray()`, `markupPercent: int`).
- `GenerationResource` JSON: `id, status, prompt, size, quality, reference_images, estimated_charge, maximum_charge, charged_amount (string|null), image_src (string|null), image_expired (bool), error_message, created_at, completed_at`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    Cache::put('pixelforge.pricing', [
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => ['characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
            'output' => ['low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420], 'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650], 'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450]],
            'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]]],
    ]);
});

it('requires authentication', function () {
    $this->get(route('generations.create'))->assertRedirect(route('login'));
    $this->post(route('generations.store'))->assertRedirect(route('login'));
});

it('shows the generate page with pricing', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page->component('generations/Create')
            ->where('pricing.rates.output_tokens', '40.00')
            ->where('markupPercent', 25));
});

it('stores a pending generation with its estimate and queues submission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ])->assertRedirect(route('generations.index'));

    $generation = $user->generations()->sole();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->maximum_charge->toDecimalString())->toBe('0.467343750');
    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generation->is($generation));
});

it('validates input', function (array $input, string $errorKey) {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), array_merge(['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'], $input))
        ->assertSessionHasErrors($errorKey);
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'long prompt' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'bad size' => [['size' => '512x512'], 'size'],
    'bad quality' => [['quality' => 'ultra'], 'quality'],
    'too many refs' => [['reference_images' => array_fill(0, 5, 'https://e.com/a.png')], 'reference_images'],
    'non-http ref' => [['reference_images' => ['ftp://e.com/a.png']], 'reference_images.0'],
]);

it('lists only the current user generations, newest first', function () {
    $user = User::factory()->create();
    $older = Generation::factory()->for($user)->succeeded()->create();
    $newer = Generation::factory()->for($user)->create();
    Generation::factory()->create();

    $this->actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.1.charged_amount', '0.381887500'));
});
```

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement**

`StoreGenerationRequest` (`php artisan make:request StoreGenerationRequest --no-interaction`):

```php
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }
```

`GenerationResource` (`php artisan make:resource GenerationResource --no-interaction`):

```php
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'estimated_charge' => $this->estimated_charge->toDecimalString(),
            'maximum_charge' => $this->maximum_charge->toDecimalString(),
            'charged_amount' => $this->charged_amount?->toDecimalString(),
            'image_src' => $this->image_src,
            'image_expired' => $this->image_src === null && $this->image_url !== null,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
```

`GenerationController` (`php artisan make:controller GenerationController --no-interaction`):

```php
<?php

namespace App\Http\Controllers;

use App\Enums\GenerationSize;
use App\Enums\GenerationQuality;
use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection(
                $request->user()->generations()->latest('id')->paginate(12),
            ),
        ]);
    }

    public function create(PixelforgeClient $client): Response
    {
        return Inertia::render('generations/Create', [
            'pricing' => $client->pricing()->toArray(),
            'markupPercent' => (int) config('services.pixelforge.markup_percent'),
        ]);
    }

    public function store(StoreGenerationRequest $request, PixelforgeClient $client, CostCalculator $costs): RedirectResponse
    {
        $referenceImages = array_values($request->validated('reference_images') ?? []);
        $size = GenerationSize::from($request->validated('size'));
        $quality = GenerationQuality::from($request->validated('quality'));

        $estimate = $costs->estimate($client->pricing(), $request->validated('prompt'), $size, $quality, count($referenceImages));

        $generation = $request->user()->generations()->create([
            'uuid' => (string) Str::uuid7(),
            'status' => GenerationStatus::Pending,
            'prompt' => $request->validated('prompt'),
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'estimated_charge' => $estimate->typicalCharge,
            'maximum_charge' => $estimate->maximumCharge,
        ]);

        SubmitGeneration::dispatch($generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation queued.')]);

        return to_route('generations.index');
    }
}
```

Routes in `routes/web.php`:

```php
Route::middleware('auth')->group(function () {
    Route::resource('generations', GenerationController::class)->only(['index', 'create', 'store']);
});
```

- [ ] **Step 4: Run the test and check it passes.** Then run `php artisan wayfinder:generate` (or let Vite do it) so `@/routes/generations` exists.

- [ ] **Step 5: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: generate and history endpoints"`

---

### Task 8: Webhooks — signature verification, dedupe, processing

**Files:**
- Create: migration `create_pixelforge_webhook_events_table`, `app/Models/PixelforgeWebhookEvent.php` (`php artisan make:model PixelforgeWebhookEvent -m --no-interaction`), `app/Http/Middleware/VerifyPixelforgeSignature.php`, `app/Http/Controllers/PixelforgeWebhookController.php`, `app/Jobs/ProcessPixelforgeWebhook.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PixelforgeWebhookTest.php`

**Interfaces:**
- Consumes: `GenerationLifecycle::apply`
- Produces: route `webhooks.pixelforge` (POST `/webhooks/pixelforge`). Table `pixelforge_webhook_events(id, event_id unique, type, payload json, delivery_attempts int, processed_at null, timestamps)`.

- [ ] **Step 1: Migration body**

```php
        Schema::create('pixelforge_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->json('payload');
            $table->unsignedInteger('deliveries')->default(1);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
```

Model: `#[Fillable(['event_id', 'type', 'payload', 'processed_at'])]`, casts `payload => array`, `processed_at => datetime`.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    Queue::fake([\App\Jobs\StoreGenerationImage::class]);
});

function sendWebhook(array $event, ?int $timestamp = null, string $secret = 'whsec_test')
{
    $body = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $body);
}

function generationEvent(Generation $generation, string $status, string $eventId = 'evt_1'): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => "generation.{$status}",
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => [
            'id' => $generation->provider_id,
            'status' => $status,
            'metadata' => ['generation_uuid' => $generation->uuid],
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'usage' => $status === 'succeeded' ? ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411] : null,
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/x.png' : null,
            'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601String() : null,
            'error' => null,
            'started_at' => now()->toIso8601String(),
            'completed_at' => $status === 'succeeded' ? now()->toIso8601String() : null,
        ]],
    ];
}

it('rejects invalid signatures', function () {
    $generation = Generation::factory()->processing()->create();

    sendWebhook(generationEvent($generation, 'succeeded'), secret: 'whsec_wrong')->assertStatus(400);
    expect(PixelforgeWebhookEvent::count())->toBe(0);
});

it('rejects stale timestamps', function () {
    $generation = Generation::factory()->processing()->create();

    sendWebhook(generationEvent($generation, 'succeeded'), timestamp: time() - 301)->assertStatus(400);
});

it('rejects a missing signature header', function () {
    $this->postJson(route('webhooks.pixelforge'), ['id' => 'evt_1'])->assertStatus(400);
});

it('applies a valid event and charges once', function () {
    $generation = Generation::factory()->processing()->create();

    sendWebhook(generationEvent($generation, 'succeeded'))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charged_amount->toDecimalString())->toBe('0.381887500');
});

it('records a duplicate delivery once and acknowledges it', function () {
    $generation = Generation::factory()->processing()->create();
    $event = generationEvent($generation, 'succeeded');

    sendWebhook($event)->assertOk();
    sendWebhook($event)->assertOk();

    expect(PixelforgeWebhookEvent::count())->toBe(1)
        ->and(PixelforgeWebhookEvent::first()->deliveries)->toBe(2);
});

it('ignores an out-of-order processing event after success', function () {
    $generation = Generation::factory()->processing()->create();

    sendWebhook(generationEvent($generation, 'succeeded', 'evt_2'))->assertOk();
    sendWebhook(generationEvent($generation, 'processing', 'evt_1'))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('matches by metadata uuid when the provider id is not stored yet', function () {
    $generation = Generation::factory()->create(); // pending, no provider_id
    $event = generationEvent($generation, 'processing');
    $event['data']['object']['id'] = 'gen_early';

    sendWebhook($event)->assertOk();

    expect($generation->refresh())->status->toBe(GenerationStatus::Processing)->provider_id->toBe('gen_early');
});

it('acknowledges ping events', function () {
    sendWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601String(), 'data' => []])->assertOk();
});
```

- [ ] **Step 3: Run it and check it fails.**

- [ ] **Step 4: Implement**

`app/Http/Middleware/VerifyPixelforgeSignature.php` (`php artisan make:middleware VerifyPixelforgeSignature --no-interaction`):

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeSignature
{
    private const int TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $parts = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ($secret === '' || ! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        return hash_equals($expected, $signature)
            && abs(time() - (int) $timestamp) <= self::TOLERANCE_SECONDS;
    }
}
```

`app/Http/Controllers/PixelforgeWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\PixelforgeWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PixelforgeWebhookController extends Controller
{
    /**
     * Ack fast (Pixelforge waits 10 s). The heavy lifting runs in a job, and the
     * unique event_id makes duplicates a no-op.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'string', 'starts_with:evt_'],
            'type' => ['required', 'string'],
            'data' => ['present', 'array'],
        ]);

        $event = PixelforgeWebhookEvent::createOrFirst(
            ['event_id' => $payload['id']],
            ['type' => $payload['type'], 'payload' => $request->json()->all()],
        );

        if (! $event->wasRecentlyCreated) {
            $event->increment('deliveries');
        }

        if ($event->processed_at === null) {
            ProcessPixelforgeWebhook::dispatch($event);
        }

        return response()->json(['received' => true]);
    }
}
```

`app/Jobs/ProcessPixelforgeWebhook.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use App\Services\Generations\GenerationLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    private const array GENERATION_EVENTS = ['generation.processing', 'generation.succeeded', 'generation.failed'];

    public function __construct(public PixelforgeWebhookEvent $event) {}

    public function handle(GenerationLifecycle $lifecycle): void
    {
        $snapshot = $this->event->payload['data']['object'] ?? null;

        if (in_array($this->event->type, self::GENERATION_EVENTS, true) && is_array($snapshot)) {
            $generation = $this->findGeneration($snapshot);

            if ($generation === null) {
                Log::warning('Pixelforge webhook for unknown generation', ['event_id' => $this->event->event_id]);
            } else {
                $lifecycle->apply($generation, $snapshot);
            }
        }

        $this->event->update(['processed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function findGeneration(array $snapshot): ?Generation
    {
        return Generation::query()->where('provider_id', $snapshot['id'])->first()
            ?? Generation::query()->where('uuid', $snapshot['metadata']['generation_uuid'] ?? '')->first();
    }
}
```

The tests use `Queue::fake([StoreGenerationImage::class])`, so `ProcessPixelforgeWebhook` runs synchronously (the test queue connection is `sync` per `phpunit.xml`; confirm this). The assertions then see the final state.

Route in `routes/web.php`:

```php
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
```

- [ ] **Step 5: Run the test and check it passes.**

- [ ] **Step 6: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: verified, deduplicated pixelforge webhooks"`

---

### Task 9: Polling fallback for lost webhooks

**Files:**
- Create: `app/Console/Commands/SyncPendingGenerations.php` (`php artisan make:command SyncPendingGenerations --no-interaction`)
- Modify: `routes/console.php`
- Test: `tests/Feature/SyncPendingGenerationsTest.php`

**Interfaces:**
- Consumes: `PixelforgeClient::retrieveGeneration`, `GenerationLifecycle::apply`
- Produces: `php artisan generations:sync`, scheduled every minute

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

it('polls stale in-flight generations and applies their state', function () {
    $stale = Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    $fresh = Generation::factory()->processing()->create();

    Http::fake(["*/generations/{$stale->provider_id}" => Http::response([
        'id' => $stale->provider_id, 'status' => 'failed', 'error' => ['code' => 'internal_error', 'message' => 'oops'],
        'completed_at' => now()->toIso8601String(),
    ])]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('keeps going when pixelforge errors', function () {
    Generation::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);

    $this->artisan('generations:sync')->assertSuccessful();
});
```

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationLifecycle;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('generations:sync {--limit=10}')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks have not arrived')]
class SyncPendingGenerations extends Command
{
    private const int STALE_AFTER_SECONDS = 90;

    public function handle(PixelforgeClient $client, GenerationLifecycle $lifecycle): int
    {
        Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Generation $generation) use ($client, $lifecycle): void {
                try {
                    $lifecycle->apply($generation, $client->retrieveGeneration($generation->provider_id));
                } catch (PixelforgeException $exception) {
                    $this->warn("{$generation->provider_id}: {$exception->errorCode}");
                }
            });

        return self::SUCCESS;
    }
}
```

If the `#[Signature]`/`#[Description]` attributes aren't available in this Laravel version, use the `$signature`/`$description` properties instead. Check what `make:command` generates.

`routes/console.php`:

```php
Schedule::command('generations:sync')->everyMinute()->withoutOverlapping();
```

- [ ] **Step 4: Run the test and check it passes.**

- [ ] **Step 5: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: polling fallback for lost webhooks"`

---

### Task 10: Keep images after the 24 h expiry

**Files:**
- Modify: `app/Jobs/StoreGenerationImage.php`
- Test: `tests/Feature/StoreGenerationImageTest.php`

Reviewers will compare numbers **after** we submit, probably more than 24 h later. Without our own copy, every image in the history would be broken by then.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('downloads the image to the public disk', function () {
    Storage::fake('public');
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
    $generation = Generation::factory()->succeeded()->create();
    Generation::query()->whereKey($generation->id)->update(['image_url' => 'https://pixelforge.promptedit.dev/images/x.png']);

    (new StoreGenerationImage($generation))->handle();

    $path = "generations/{$generation->uuid}.png";
    Storage::disk('public')->assertExists($path);
    expect($generation->refresh()->image_path)->toBe($path);
});

it('skips generations that already have a stored image', function () {
    Http::preventStrayRequests();
    $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/x.png']);

    (new StoreGenerationImage($generation))->handle();

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement**

```php
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public Generation $generation) {}

    public function handle(): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        $image = Http::timeout(30)->get($generation->image_url)->throw()->body();
        $path = "generations/{$generation->uuid}.png";

        Storage::disk('public')->put($path, $image);
        $generation->update(['image_path' => $path]);
    }
}
```

- [ ] **Step 4: Run the test and check it passes.** Then run `php artisan storage:link`.

- [ ] **Step 5: Commit** — `vendor/bin/pint --dirty --format agent && git add -A && git commit -m "feat: persist generated images beyond url expiry"`

---

### Task 11: Frontend — Generate page with live estimate, History page with live status

**Prerequisite:** activate the `inertia-vue-development` and `vue-best-practices` skills. Run `search-docs` with queries `["usePoll", "polling", "useForm"]`, packages `["inertiajs/inertia-laravel"]`, to confirm the v3 signatures used below.

**Files:**
- Create: `resources/js/lib/money.ts`, `resources/js/lib/pricing.ts`, `resources/js/types/generation.ts`, `resources/js/pages/generations/Create.vue`, `resources/js/pages/generations/Index.vue`, `resources/js/components/GenerationCard.vue`, `resources/js/components/GenerationStatusBadge.vue`
- Modify: `resources/js/components/AppSidebar.vue` (nav items), `resources/js/types/index.ts` (export)
- Test: no automated tests (layout/UI). Verify with `npm run types:check`, `npm run check`, and the manual run in Step 6.

**Interfaces — Consumes:** props from Task 7, Wayfinder `@/routes/generations` (`index`, `create`, `store`).

- [ ] **Step 1: Types and helpers**

`resources/js/types/generation.ts`:

```ts
export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';
export type GenerationSize = '1024x1024' | '1536x1024' | '1024x1536';
export type GenerationQuality = 'low' | 'medium' | 'high';
export type TokenKind = 'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens';

export type Pricing = {
    rates: Record<TokenKind, string>;
    characters_per_text_token: number;
    text_token_variance_percent: number;
    tokens_per_reference_image: number;
    output_tokens: Record<GenerationQuality, Record<GenerationSize, number>>;
    reasoning_tokens: Record<GenerationQuality, { min: number; max: number }>;
};

export type Generation = {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    reference_images: string[];
    estimated_charge: string;
    maximum_charge: string;
    charged_amount: string | null;
    image_src: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};
```

`resources/js/lib/money.ts`:

```ts
const NANOS_PER_DOLLAR = 1_000_000_000;

/** Parse a 9-decimal USD string into integer nanodollars. */
export function decimalToNanos(amount: string): number {
    const [whole, fraction = ''] = amount.split('.');

    return Number(whole) * NANOS_PER_DOLLAR + Number(fraction.padEnd(9, '0'));
}

/** Display a USD amount. Sub-cent values keep 4 decimals so they are never shown as $0.00. */
export function formatUsd(nanos: number): string {
    return `$${(nanos / NANOS_PER_DOLLAR).toFixed(4)}`;
}

export function formatUsdString(amount: string): string {
    return formatUsd(decimalToNanos(amount));
}
```

`resources/js/lib/pricing.ts`. This is a preview only. The server recomputes the estimate in `CostCalculator`, and that is what gets stored:

```ts
import { decimalToNanos } from '@/lib/money';
import type { GenerationQuality, GenerationSize, Pricing, TokenKind } from '@/types/generation';

type Tokens = Record<TokenKind, number>;

function providerCostNanos(tokens: Tokens, pricing: Pricing): number {
    const nanoTokenRate = (Object.keys(pricing.rates) as TokenKind[]).reduce(
        (sum, kind) => sum + tokens[kind] * decimalToNanos(pricing.rates[kind]),
        0,
    );

    return Math.ceil(nanoTokenRate / 1_000_000);
}

function charge(providerNanos: number, markupPercent: number): number {
    return Math.ceil((providerNanos * (100 + markupPercent)) / 100);
}

export function estimateCharge(
    pricing: Pricing,
    markupPercent: number,
    prompt: string,
    size: GenerationSize,
    quality: GenerationQuality,
    referenceImageCount: number,
): { typical: number; maximum: number } {
    const nominalText = Math.ceil([...prompt].length / pricing.characters_per_text_token);
    const maximumText = Math.ceil((nominalText * (100 + pricing.text_token_variance_percent)) / 100);
    const reasoning = pricing.reasoning_tokens[quality];
    const fixed = {
        input_image_tokens: referenceImageCount * pricing.tokens_per_reference_image,
        output_tokens: pricing.output_tokens[quality][size],
    };

    return {
        typical: charge(providerCostNanos({ ...fixed, input_text_tokens: nominalText, reasoning_tokens: Math.floor((reasoning.min + reasoning.max) / 2) }, pricing), markupPercent),
        maximum: charge(providerCostNanos({ ...fixed, input_text_tokens: maximumText, reasoning_tokens: reasoning.max }, pricing), markupPercent),
    };
}
```

`[...prompt].length` counts Unicode code points, matching PHP's `mb_strlen`.

- [ ] **Step 2: `Create.vue`.** It has a prompt textarea with a character counter (max 4000), size and quality selects (the existing `@/components/ui/select`), and up to 4 reference URL inputs with add/remove. A sticky **cost panel** shows: "Estimated **$0.0728**, at most $0.1300". Below that, one line of explanation: "Final price = Pixelforge tokens + 25%. You're charged only if the image succeeds." Plus a breakdown of what drives the cost (quality, size, references). The submit button shows a spinner while `form.processing`.

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd } from '@/lib/money';
import { estimateCharge } from '@/lib/pricing';
import { create, index, store } from '@/routes/generations';
import type { GenerationQuality, GenerationSize, Pricing } from '@/types/generation';

const props = defineProps<{ pricing: Pricing; markupPercent: number }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'History', href: index() }, { title: 'Generate', href: create() }] },
});

const MAX_PROMPT = 4000;
const MAX_REFERENCES = 4;

const form = useForm({
    prompt: '',
    size: '1024x1024' as GenerationSize,
    quality: 'medium' as GenerationQuality,
    reference_images: [] as string[],
});

const estimate = computed(() =>
    estimateCharge(props.pricing, props.markupPercent, form.prompt, form.size, form.quality, form.reference_images.filter(Boolean).length),
);

function addReference(): void {
    if (form.reference_images.length < MAX_REFERENCES) {
        form.reference_images.push('');
    }
}

function submit(): void {
    form.transform((data) => ({ ...data, reference_images: data.reference_images.filter(Boolean) }))
        .post(store.url(), { onSuccess: () => form.reset() });
}
</script>

<template>
    <Head title="Generate" />
    <div class="grid gap-6 p-4 lg:grid-cols-[1fr_320px]">
        <form class="space-y-6" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="prompt">Prompt</Label>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    :maxlength="MAX_PROMPT"
                    rows="6"
                    required
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    placeholder="A lighthouse at dusk, oil painting"
                />
                <div class="flex justify-between text-xs text-muted-foreground">
                    <InputError :message="form.errors.prompt" />
                    <span>{{ [...form.prompt].length }} / {{ MAX_PROMPT }}</span>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label>Size</Label>
                    <Select v-model="form.size">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="1024x1024">Square · 1024×1024</SelectItem>
                            <SelectItem value="1536x1024">Landscape · 1536×1024</SelectItem>
                            <SelectItem value="1024x1536">Portrait · 1024×1536</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.size" />
                </div>
                <div class="space-y-2">
                    <Label>Quality</Label>
                    <Select v-model="form.quality">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="low">Low</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.quality" />
                </div>
            </div>

            <div class="space-y-2">
                <Label>Reference images <span class="text-muted-foreground">(optional, up to 4)</span></Label>
                <div v-for="(_, i) in form.reference_images" :key="i" class="flex gap-2">
                    <Input v-model="form.reference_images[i]" type="url" placeholder="https://…" />
                    <Button type="button" variant="ghost" @click="form.reference_images.splice(i, 1)">Remove</Button>
                </div>
                <InputError v-for="(_, i) in form.reference_images" :key="`e${i}`" :message="form.errors[`reference_images.${i}` as keyof typeof form.errors]" />
                <Button v-if="form.reference_images.length < MAX_REFERENCES" type="button" variant="outline" size="sm" @click="addReference">
                    Add reference image
                </Button>
            </div>

            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" /> Generate
            </Button>
        </form>

        <Card class="h-fit lg:sticky lg:top-4">
            <CardHeader><CardTitle>Cost</CardTitle></CardHeader>
            <CardContent class="space-y-3 text-sm">
                <p class="text-3xl font-semibold tabular-nums">{{ formatUsd(estimate.typical) }}</p>
                <p class="text-muted-foreground">Estimated · at most <span class="tabular-nums">{{ formatUsd(estimate.maximum) }}</span></p>
                <p class="text-muted-foreground">
                    The final price is Pixelforge's token cost plus {{ markupPercent }}%. Part of it is only known once the image is done.
                    <strong>You're only charged if the image succeeds.</strong>
                </p>
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 3: `GenerationStatusBadge.vue` and `GenerationCard.vue`**

Status mapping (keep it in the badge component):

| status | label | style | hint |
|---|---|---|---|
| pending | Queued | secondary + spinner | "Waiting for a free slot at Pixelforge" |
| queued | Queued | secondary + spinner | "In Pixelforge's queue" |
| processing | Processing | default + spinner | "Usually 5–30 seconds" |
| succeeded | Succeeded | green | — |
| failed | Failed | destructive | — |

`GenerationCard.vue` (prop `generation: Generation`):
- Image area with `aspect-ratio` from `size`:
  - `image_src` → `<img loading="lazy">`
  - non-final → `<Skeleton class="animate-pulse">`
  - `image_expired` → muted "Image link expired" placeholder
  - failed → icon + `error_message`
- Prompt (2-line clamp, full text in `title`), with size and quality as small text.
- Cost row:
  - `charged_amount !== null` → "Charged **$0.3819**" (exact amount in a `title`); failed shows "Not charged".
  - otherwise "Estimated $0.0728 · max $0.1300".
- Relative `created_at` (use `Intl.RelativeTimeFormat` or a plain `toLocaleString`, no new dependency).

- [ ] **Step 4: `Index.vue`.** It shows a grid of `GenerationCard`, an empty state that links to `create()`, and simple prev/next pagination from `generations.links`/`meta`. Live status:

```ts
import { usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps<{ generations: { data: Generation[]; links: { prev: string | null; next: string | null } } }>();

const hasActiveGenerations = computed(() =>
    props.generations.data.some((generation) => !['succeeded', 'failed'].includes(generation.status)),
);

const { start, stop } = usePoll(3000, { only: ['generations'] }, { autoStart: false });

watch(hasActiveGenerations, (active) => (active ? start() : stop()), { immediate: true });
```

The page polls only while something is in flight, and stops by itself once everything is final.

- [ ] **Step 5: Navigation.** In `AppSidebar.vue`, replace the Dashboard item with `{ title: 'Generate', href: create(), icon: Sparkles }` and `{ title: 'History', href: index(), icon: Images }` (from `@lucide/vue`). Point the logo link to `create()`. Remove the starter-kit footer links (Repository/Documentation).

- [ ] **Step 6: Verify**

Run: `npm run types:check && npm run check && npm run build`
Expected: no errors.

Manual check (`composer run dev`, with real keys in `.env` and a tunnel URL set as the webhook in the Pixelforge dashboard):
1. Type a prompt and watch the estimate update as you change quality, size and references.
2. Submit `a cat [succeed]`. History goes Queued → Processing → Succeeded, the charge appears, and the image shows.
3. Submit `x [fail]`. It goes to Failed with "Not charged".
4. Submit `x [blocked]`. It goes to Failed with the content-policy message.
5. Submit `x [duplicate] [succeed]`. It's charged once. Check with `database-query`: `select event_id, deliveries from pixelforge_webhook_events`.
6. Submit 5 at once. Only 3 are in flight at any time and the others wait in Queued.

- [ ] **Step 7: Commit** — `git add -A && git commit -m "feat: generate and history pages with live estimate and status"`

---

### Task 12: Single-container Docker image

**Files:**
- Create: `Dockerfile`, `docker/supervisord.conf`, `docker/entrypoint.sh`, `compose.yaml`, `.dockerignore`

Design: one image, one container. supervisord runs 3 processes (FrankenPHP web server, `queue:work`, `schedule:work`). SQLite and the stored images live on one named volume mounted at `/app/storage`. There's no Redis and no MySQL, and this is where the real RAM savings come from.

- [ ] **Step 1: `Dockerfile`**

```dockerfile
# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1-php8.5 AS base
RUN install-php-extensions pdo_sqlite bcmath intl pcntl opcache zip \
    && apt-get update && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app

FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && npm run build \
    && rm -rf node_modules

FROM base AS runtime
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite DB_DATABASE=/app/storage/database.sqlite \
    QUEUE_CONNECTION=database CACHE_STORE=database SESSION_DRIVER=database
COPY --from=build /app /app
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
EXPOSE 8080
VOLUME ["/app/storage"]
ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/app.conf"]
```

- [ ] **Step 2: `docker/supervisord.conf`**

```ini
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
user=root

[program:web]
command=frankenphp php-server --root /app/public --listen :8080
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
autorestart=true

[program:queue]
command=php /app/artisan queue:work --sleep=1 --max-time=3600 --memory=128
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
autorestart=true
stopwaitsecs=60

[program:scheduler]
command=php /app/artisan schedule:work
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
autorestart=true
```

- [ ] **Step 3: `docker/entrypoint.sh`**

```sh
#!/bin/sh
set -e
cd /app
mkdir -p storage/app/public/generations storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan migrate --force --no-interaction
php artisan optimize
exec "$@"
```

- [ ] **Step 4: `compose.yaml` and `.dockerignore`**

```yaml
services:
  app:
    build: .
    restart: unless-stopped
    env_file: .env.production
    ports:
      - "127.0.0.1:8080:8080"
    volumes:
      - app-storage:/app/storage
    mem_limit: 512m
volumes:
  app-storage:
```

`.dockerignore`:

```
.git
node_modules
vendor
storage/*.key
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
database/database.sqlite
.env
.env.*
!.env.example
public/build
public/hot
tests
graft
.grok
```

- [ ] **Step 5: Smoke test locally**

```bash
cp .env.example .env.production   # fill APP_KEY (php artisan key:generate --show), APP_URL, PIXELFORGE_*
docker compose up --build -d
curl -fsS http://127.0.0.1:8080/up
docker compose exec app php artisan queue:monitor database:default
docker stats --no-stream
```

Expected: `/up` returns 200, and memory use stays under about 250 MB.

- [ ] **Step 6: Commit** — `git add -A && git commit -m "chore: single-container docker image (frankenphp + queue + scheduler)"`

---

### Task 13: Deploy and verify end-to-end against Pixelforge

- [ ] **Step 1: In the Pixelforge dashboard,** create a **production** API key. Set its webhook URL to `https://<domain>/webhooks/pixelforge` and copy the key and `whsec_` secret into `.env.production`.
- [ ] **Step 2: Deploy on the host:** `docker compose up -d --build` behind the host's HTTPS reverse proxy. Set `APP_URL=https://<domain>`. Pixelforge doesn't follow redirects, so the webhook URL must be the final https URL.
- [ ] **Step 3: Click "Send test event"** in the dashboard and check that the delivery log shows 200.
- [ ] **Step 4: Run the manual checks** from Task 11 Step 6 on production. Leave about 5 succeeded and 2 failed generations for the reviewers.
- [ ] **Step 5: Compare money.** Sum `provider_cost` of succeeded generations grouped by `date(completed_at)` (`database-query`), and compare it with `curl -H "Authorization: Bearer $KEY" https://pixelforge.promptedit.dev/v1/usage`. The totals must match to the nanodollar.
- [ ] **Step 6: Write a short `README.md`** (deliverable for reviewers) covering setup, architecture (lifecycle compare-and-set, idempotency key = uuid, webhook dedupe, polling fallback) and money decisions (nanodollars, ceil markup, 4-decimal display). Known limitation: if a submit's transport fails repeatedly, the generation is marked failed locally, so a provider-side success after that is not reflected.
- [ ] **Step 7: Push** to a public GitHub repo (ask the user before creating it).

---

## Self-Review

- **Spec coverage:**
  - Generate page + estimate → T7, T11
  - +25% markup, actual cost, failed free → T2, T5
  - Sub-cent decision → Global Constraints
  - History with live status, estimated and actual cost, image → T7, T10, T11
  - Webhook verification, dedupe, out-of-order → T5, T8
  - Lost webhooks → T9
  - Limits, overload, idempotency → T6
  - Auth → existing Fortify + T0
  - Deploy → T12, T13
- **Type consistency:**
  - `GenerationLifecycle::apply/fail` is used in T6, T8 and T9.
  - `Money` methods match between T1 and T2/T5.
  - `estimated_charge`, `maximum_charge` and `charged_amount` names match between T4, T7 and T11.
