# Phase 2 — Stretch Goals (Admin Report + Credit Balances) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Prerequisite:** Phase 1 (`2026-11-20-phase-1-core-generations.md`) is fully merged, deployed and verified.

**Goal:** Operators get a per-day, per-user report of credit spend, provider cost and profit, reconciled against Pixelforge's `GET /v1/usage`. Users get a credit balance, a fake "Buy credits" flow, and generation is blocked when they can't afford the worst-case price.

**Architecture:** The report aggregates our own `generations` rows by the **UTC day they completed**, which is how Pixelforge bills. It shows Pixelforge's `/v1/usage` per day next to ours, with a match/mismatch flag. Balances use an append-only `credit_transactions` ledger, with a denormalized `users.credit_balance` updated in the same transaction. On submit we **hold** the maximum possible charge. When the generation reaches a final status, `GenerationLifecycle::afterTransition` (which runs only on a winning compare-and-set) **releases** the hold and **charges** the actual amount, or only releases it on failure. A `unique(generation_id, type)` constraint is a second, database-level guarantee that nobody is charged twice.

**Tech Stack:** same as Phase 1.

**Spec:** `docs/superpowers/specs/2026-11-20-pixelforge-assessment.md`

## Global Constraints

- All Phase 1 global constraints apply (nanodollars, no floats, ceil markup, 4-decimal display).
- Report dates are UTC calendar days of `completed_at`. Only `succeeded` generations have cost. Failed ones count as 0 and appear in neither column.
- `/v1/usage` reports **per API key**. The report compares against the production key only (the one configured in `PIXELFORGE_API_KEY`). The max range is 92 days, so the UI clamps to that.
- Ledger amounts are signed: purchase `+`, hold `−maximum_charge`, release `+maximum_charge`, charge `−charged_amount`. `users.credit_balance` always equals `SUM(credit_transactions.amount)` for that user.
- The hold uses the **maximum** estimate. Because the hold covers the worst case, the balance never goes negative through normal use.
- Purchase packs (fake): $1, $5 and $20. No payment provider.
- Admins: `users.is_admin` boolean, set by an artisan command. No UI for granting it.

## File Map

| File | Responsibility |
|---|---|
| migration `add_is_admin_and_credit_balance_to_users_table` | `is_admin`, `credit_balance` columns |
| `app/Console/Commands/PromoteUserToAdmin.php` | `users:promote {email}` |
| `app/Providers/AppServiceProvider.php` | `viewAdminReport` gate |
| `app/Services/Pixelforge/PixelforgeClient.php` | + `usage(CarbonImmutable, CarbonImmutable)` |
| `app/Services/Reports/SpendReport.php` | Per-day/per-user aggregation + reconciliation |
| `app/Http/Controllers/Admin/SpendReportController.php`, `resources/js/pages/admin/SpendReport.vue` | Admin page |
| migration `create_credit_transactions_table`, `app/Models/CreditTransaction.php`, `app/Enums/CreditTransactionType.php` | Ledger |
| `app/Services/Credits/CreditLedger.php`, `app/Services/Credits/InsufficientCredits.php` | purchase / hold / settle / release |
| `app/Http/Controllers/CreditController.php`, `resources/js/pages/credits/Index.vue` | Balance + buy credits |
| `app/Http/Middleware/HandleInertiaRequests.php` | Share balance + is_admin |

---

### Task 1: Admin flag, gate and promote command

**Files:**
- Create: migration (`php artisan make:migration add_is_admin_and_credit_balance_to_users_table --table=users --no-interaction`), `app/Console/Commands/PromoteUserToAdmin.php`
- Modify: `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/AdminAccessTest.php`

**Interfaces — Produces:** `User::$is_admin: bool`, `User::$credit_balance: Money` (`MoneyCast`), `UserFactory::admin()`, `Gate 'viewAdminReport'`, command `users:promote {email}`.

- [ ] **Step 1: Migration**

```php
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->bigInteger('credit_balance')->default(0)->comment('nanodollars');
        });
```

(`down()` drops both columns.)

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('only lets admins view the report', function () {
    expect(Gate::forUser(User::factory()->admin()->create())->allows('viewAdminReport'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('viewAdminReport'))->toBeFalse();
});

it('promotes a user by email', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('users:promote', ['email' => 'ops@example.com'])->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

it('fails for an unknown email', function () {
    $this->artisan('users:promote', ['email' => 'nobody@example.com'])->assertFailed();
});
```

- [ ] **Step 3: Run it and check it fails.**

- [ ] **Step 4: Implement**
  - `User` casts: add `'is_admin' => 'boolean', 'credit_balance' => MoneyCast::class`. Add `@property bool $is_admin` and `@property Money $credit_balance` to the PHPDoc. Don't add either to `#[Fillable]`.
  - `UserFactory::admin()`: `return $this->state(fn (): array => ['is_admin' => true]);`
  - `AppServiceProvider::boot()`: `Gate::define('viewAdminReport', fn (User $user): bool => $user->is_admin);`
  - Command:

```php
#[Signature('users:promote {email}')]
#[Description('Grant admin access to a user')]
class PromoteUserToAdmin extends Command
{
    public function handle(): int
    {
        $updated = User::query()->where('email', $this->argument('email'))->update(['is_admin' => true]);

        if ($updated === 0) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $this->info('User promoted to admin.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the test and check it passes.** Commit: `feat: admin flag, gate and promote command`.

---

### Task 2: `PixelforgeClient::usage`

**Files:**
- Modify: `app/Services/Pixelforge/PixelforgeClient.php`
- Test: append to `tests/Feature/PixelforgeClientTest.php`

**Interfaces — Produces:** `usage(CarbonImmutable $start, CarbonImmutable $end): array<string, Money>`. It maps `YYYY-MM-DD` to `cost_usd` for every day in range (zero days included, as the API returns them).

- [ ] **Step 1: Failing test**

```php
it('returns provider cost per day from the usage endpoint', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/usage*' => Http::response([
        'object' => 'usage', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02',
        'data' => [
            ['date' => '2026-11-01', 'generations' => 3, 'cost_usd' => '0.169080000'],
            ['date' => '2026-11-02', 'generations' => 0, 'cost_usd' => '0.000000000'],
        ],
        'total' => ['generations' => 3, 'cost_usd' => '0.169080000'],
    ])]);

    $usage = app(PixelforgeClient::class)->usage(
        \Carbon\CarbonImmutable::parse('2026-11-01'), \Carbon\CarbonImmutable::parse('2026-11-02'),
    );

    expect($usage['2026-11-01']->nanos)->toBe(169_080_000)
        ->and($usage['2026-11-02']->nanos)->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'start_date=2026-11-01')
        && str_contains($request->url(), 'end_date=2026-11-02'));
});
```

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement**

```php
    /**
     * Provider cost billed to this API key, per UTC day of completion.
     *
     * @return array<string, Money>
     */
    public function usage(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $payload = $this->send(fn (): Response => $this->authenticated()->get('/usage', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]));

        return collect($payload['data'])
            ->mapWithKeys(fn (array $day): array => [$day['date'] => Money::fromDecimalString($day['cost_usd'])])
            ->all();
    }
```

- [ ] **Step 4: Run the test and check it passes.** Commit: `feat: pixelforge usage endpoint`.

---

### Task 3: `SpendReport` aggregation and reconciliation

**Files:**
- Create: `app/Services/Reports/SpendReport.php`
- Test: `tests/Feature/SpendReportTest.php`

**Interfaces:**
- Consumes: `PixelforgeClient::usage`, `Generation`, `Money`
- Produces: `SpendReport::build(CarbonImmutable $start, CarbonImmutable $end): array` with this shape:

```php
array{
    rows: list<array{date: string, user_id: int, user_name: string, user_email: string, generations: int, charged: string, provider_cost: string, profit: string}>,
    days: list<array{date: string, generations: int, charged: string, provider_cost: string, profit: string, pixelforge_cost: string|null, matches: bool|null}>,
    totals: array{generations: int, charged: string, provider_cost: string, profit: string},
    usage_error: string|null,
}
```

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Reports\SpendReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('aggregates succeeded generations per completion day and user, and reconciles with pixelforge', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    Generation::factory()->for($alice)->succeeded()->count(2)->create(['completed_at' => '2026-11-01 23:59:00']);
    Generation::factory()->for($bob)->succeeded()->create(['completed_at' => '2026-11-02 00:00:01']);
    Generation::factory()->for($bob)->failed()->create(['completed_at' => '2026-11-02 10:00:00']);
    Generation::factory()->for($bob)->processing()->create(); // not completed: excluded

    Http::fake(['*/usage*' => Http::response(['data' => [
        ['date' => '2026-11-01', 'cost_usd' => '0.611020000'],  // 2 x 0.305510000
        ['date' => '2026-11-02', 'cost_usd' => '0.305510001'],  // off by one nanodollar
    ]])]);

    $report = app(SpendReport::class)->build(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'));

    expect($report['rows'])->toHaveCount(2)
        ->and($report['rows'][0])->toMatchArray([
            'date' => '2026-11-01', 'user_name' => 'Alice', 'generations' => 2,
            'charged' => '0.763775000', 'provider_cost' => '0.611020000', 'profit' => '0.152755000',
        ])
        ->and($report['days'][0]['matches'])->toBeTrue()
        ->and($report['days'][1]['matches'])->toBeFalse()
        ->and($report['totals']['provider_cost'])->toBe('0.916530000');
});

it('still reports our numbers when the usage api is unavailable', function () {
    Generation::factory()->succeeded()->create(['completed_at' => '2026-11-01 12:00:00']);
    Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);

    $report = app(SpendReport::class)->build(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01'));

    expect($report['rows'])->toHaveCount(1)
        ->and($report['days'][0]['pixelforge_cost'])->toBeNull()
        ->and($report['usage_error'])->not->toBeNull();
});
```

Before writing this, confirm `config('app.timezone')` is `UTC`. The report relies on `completed_at` being stored in UTC.

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

final readonly class SpendReport
{
    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array{rows: list<array<string, mixed>>, days: list<array<string, mixed>>, totals: array<string, mixed>, usage_error: string|null}
     */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereBetween('generations.completed_at', [$start->startOfDay(), $end->endOfDay()])
            ->selectRaw('date(generations.completed_at) as day, users.id as user_id, users.name as user_name, users.email as user_email')
            ->selectRaw('count(*) as generations, sum(generations.charged_amount) as charged, sum(generations.provider_cost) as provider_cost')
            ->groupByRaw('date(generations.completed_at), users.id, users.name, users.email')
            ->orderBy('day')
            ->orderBy('user_name')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => $this->line([
                'date' => $row->day,
                'user_id' => (int) $row->user_id,
                'user_name' => $row->user_name,
                'user_email' => $row->user_email,
            ], (int) $row->generations, (int) $row->charged, (int) $row->provider_cost));

        [$pixelforge, $usageError] = $this->pixelforgeUsage($start, $end);

        $days = collect(CarbonPeriod::create($start, $end))->map(function ($date) use ($rows, $pixelforge): array {
            $day = $date->toDateString();
            $dayRows = $rows->where('date', $day);
            $ours = Money::fromNanos($dayRows->sum('provider_cost_nanos'));
            $theirs = $pixelforge[$day] ?? null;

            return $this->line(['date' => $day], $dayRows->sum('generations'), $dayRows->sum('charged_nanos'), $ours->nanos) + [
                'pixelforge_cost' => $theirs?->toDecimalString(),
                'matches' => $theirs === null ? null : $theirs->nanos === $ours->nanos,
            ];
        });

        return [
            'rows' => $this->strip($rows),
            'days' => $this->strip($days),
            'totals' => $this->strip(collect([$this->line([], $rows->sum('generations'), $rows->sum('charged_nanos'), $rows->sum('provider_cost_nanos'))]))[0],
            'usage_error' => $usageError,
        ];
    }

    /**
     * @param  array<string, mixed>  $keys
     * @return array<string, mixed>
     */
    private function line(array $keys, int $generations, int $chargedNanos, int $providerNanos): array
    {
        return $keys + [
            'generations' => $generations,
            'charged' => Money::fromNanos($chargedNanos)->toDecimalString(),
            'provider_cost' => Money::fromNanos($providerNanos)->toDecimalString(),
            'profit' => Money::fromNanos($chargedNanos - $providerNanos)->toDecimalString(),
            'charged_nanos' => $chargedNanos,
            'provider_cost_nanos' => $providerNanos,
        ];
    }

    /**
     * Drop the internal nanos helpers before handing data to the UI.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function strip(Collection $lines): array
    {
        return $lines->map(fn (array $line): array => collect($line)->except(['charged_nanos', 'provider_cost_nanos'])->all())->values()->all();
    }

    /**
     * @return array{0: array<string, Money>, 1: string|null}
     */
    private function pixelforgeUsage(CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            return [$this->client->usage($start, $end), null];
        } catch (PixelforgeException $exception) {
            return [[], "Pixelforge usage unavailable ({$exception->errorCode})."];
        }
    }
}
```

`date(...)` works on both SQLite and Postgres. On MySQL it also works. The test is the safety net if the DB engine ever changes.

- [ ] **Step 4: Run the test and check it passes.** Commit: `feat: spend report with pixelforge reconciliation`.

---

### Task 4: Admin report page

**Files:**
- Create: `app/Http/Controllers/Admin/SpendReportController.php`, `app/Http/Requests/Admin/SpendReportRequest.php`, `resources/js/pages/admin/SpendReport.vue`
- Modify: `routes/web.php`, `app/Http/Middleware/HandleInertiaRequests.php` (share `auth.user.is_admin`; it's already serialized if the user model is shared, so verify that), `resources/js/components/AppSidebar.vue` (an "Admin report" item visible only when `is_admin`)
- Test: `tests/Feature/Admin/SpendReportControllerTest.php`

**Interfaces — Produces:** route `admin.spend-report` (GET `/admin/spend-report?start_date&end_date`), Inertia page `admin/SpendReport` with props `report` (Task 3 shape), `filters: {start_date, end_date}`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Http::fake(['*/usage*' => Http::response(['data' => []])]));

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.spend-report'))->assertForbidden();
});

it('defaults to the last 7 utc days', function () {
    $this->travelTo('2026-11-20 12:00:00');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.spend-report'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/SpendReport')
            ->where('filters.start_date', '2026-11-14')
            ->where('filters.end_date', '2026-11-20')
            ->has('report.days', 7));
});

it('rejects ranges over 92 days', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.spend-report', ['start_date' => '2026-01-01', 'end_date' => '2026-11-01']))
        ->assertSessionHasErrors('end_date');
});
```

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement**

`SpendReportRequest`:

```php
    public function authorize(): bool
    {
        return $this->user()->can('viewAdminReport');
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', function (string $attribute, mixed $value, Closure $fail): void {
                $start = $this->date('start_date');
                if ($start !== null && $start->diffInDays($this->date('end_date')) > 91) {
                    $fail('The range can cover at most 92 days.');
                }
            }],
        ];
    }

    public function startDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('start_date') ?? $this->endDate()->subDays(6)->toDateString());
    }

    public function endDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('end_date') ?? now('UTC')->toDateString());
    }
```

Controller:

```php
class SpendReportController extends Controller
{
    public function __invoke(SpendReportRequest $request, SpendReport $report): Response
    {
        return Inertia::render('admin/SpendReport', [
            'filters' => ['start_date' => $request->startDate()->toDateString(), 'end_date' => $request->endDate()->toDateString()],
            'report' => $report->build($request->startDate(), $request->endDate()),
        ]);
    }
}
```

Route, inside the `auth` group: `Route::get('admin/spend-report', SpendReportController::class)->name('admin.spend-report');`

`SpendReport.vue` layout:
1. Header with two `<Input type="date">` fields and an "Apply" button (`router.get(spendReport.url({ query: filters }))`).
2. Three stat cards for the range: **Credit spend** (charged), **Provider cost**, **Profit**.
3. A "Daily reconciliation" table: Date · Generations · Credit spend · Provider cost (ours) · Pixelforge `/v1/usage` · status (✓ green "Matches" / ⚠ amber "Differs by $x" / "—" when unavailable). If `usage_error` is set, show an `Alert` above the table.
4. A "Per user" table: Date · User (name + email) · Generations · Credit spend · Provider cost · Profit.

Use `formatUsdString` for display, with the exact 9-decimal value in `title`. Use the `tabular-nums` class on money columns.

- [ ] **Step 4: Run the test and check it passes, then `npm run types:check && npm run build`.** Commit: `feat: admin spend report page`.

---

### Task 5: Credit ledger

**Files:**
- Create: migration `create_credit_transactions_table`, `app/Models/CreditTransaction.php` (`php artisan make:model CreditTransaction -m --no-interaction`), `app/Enums/CreditTransactionType.php`, `app/Services/Credits/CreditLedger.php`, `app/Services/Credits/InsufficientCredits.php`
- Modify: `app/Models/User.php` (`creditTransactions(): HasMany`)
- Test: `tests/Feature/CreditLedgerTest.php`

**Interfaces — Produces:**
- `CreditTransactionType::{Purchase,Hold,Release,Charge}`
- `CreditLedger::purchase(User $user, Money $amount): void`
- `CreditLedger::hold(Generation $generation): void` throws `InsufficientCredits` (with public `Money $required`, `Money $available`)
- `CreditLedger::settle(Generation $generation): void`, which releases the hold (if one exists) and charges `charged_amount`
- `CreditLedger::release(Generation $generation): void`, which releases the hold (if one exists)
- Each method is idempotent: a second call for the same generation and type is a no-op.

- [ ] **Step 1: Migration**

```php
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->bigInteger('amount')->comment('signed nanodollars');
            $table->bigInteger('balance_after')->comment('nanodollars');
            $table->timestamps();

            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'id']);
        });
```

Multiple `NULL` `generation_id` values (purchases) don't conflict under a unique index in SQLite or Postgres.

- [ ] **Step 2: Failing test**

```php
<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\InsufficientCredits;
use App\Support\Money;

function ledger(): CreditLedger
{
    return app(CreditLedger::class);
}

it('adds purchased credits', function () {
    $user = User::factory()->create();

    ledger()->purchase($user, Money::fromDollars(5));

    expect($user->refresh()->credit_balance->toDecimalString())->toBe('5.000000000')
        ->and($user->creditTransactions()->sole()->balance_after)->toBe(5_000_000_000);
});

it('holds the maximum charge and refuses when the balance is too low', function () {
    $user = User::factory()->create();
    ledger()->purchase($user, Money::fromNanos(100_000_000));
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(130_000_000)]);

    expect(fn () => ledger()->hold($generation))->toThrow(InsufficientCredits::class);
    expect($user->refresh()->credit_balance->nanos)->toBe(100_000_000);
});

it('settles to the actual charge exactly once', function () {
    $user = User::factory()->create();
    ledger()->purchase($user, Money::fromDollars(1));
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    ledger()->hold($generation);

    Generation::query()->whereKey($generation->id)->update(['charged_amount' => 381_887_500]);
    ledger()->settle($generation->refresh());
    ledger()->settle($generation);

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000 - 381_887_500)
        ->and($user->creditTransactions()->count())->toBe(4); // purchase, hold, release, charge
});

it('releases the hold on failure', function () {
    $user = User::factory()->create();
    ledger()->purchase($user, Money::fromDollars(1));
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    ledger()->hold($generation);

    ledger()->release($generation);
    ledger()->release($generation);

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000);
});

it('keeps the cached balance equal to the ledger sum', function () {
    $user = User::factory()->create();
    ledger()->purchase($user, Money::fromDollars(2));
    $generation = Generation::factory()->for($user)->create(['maximum_charge' => Money::fromNanos(300_000_000)]);
    ledger()->hold($generation);

    expect($user->refresh()->credit_balance->nanos)->toBe((int) $user->creditTransactions()->sum('amount'));
});
```

- [ ] **Step 3: Run it and check it fails.**

- [ ] **Step 4: Implement**

`CreditTransactionType`: `Purchase = 'purchase'`, `Hold = 'hold'`, `Release = 'release'`, `Charge = 'charge'`.

`CreditTransaction` model: `#[Fillable(['user_id', 'generation_id', 'type', 'amount', 'balance_after'])]`, casts `type => CreditTransactionType::class`, `amount => 'integer'`, `balance_after => 'integer'`, and a `belongsTo` to user and generation.

`InsufficientCredits`:

```php
final class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly Money $required, public readonly Money $available)
    {
        parent::__construct('Not enough credits.');
    }
}
```

`CreditLedger`:

```php
<?php

namespace App\Services\Credits;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Append-only credit ledger. users.credit_balance is a cache of SUM(amount), always
 * written in the same transaction as the ledger row.
 */
final class CreditLedger
{
    public function purchase(User $user, Money $amount): void
    {
        DB::transaction(fn () => $this->record($user->id, null, CreditTransactionType::Purchase, $amount));
    }

    public function hold(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $required = $generation->maximum_charge;

            $reserved = User::query()
                ->whereKey($generation->user_id)
                ->where('credit_balance', '>=', $required->nanos)
                ->exists();

            if (! $reserved) {
                throw new InsufficientCredits($required, User::query()->findOrFail($generation->user_id)->credit_balance);
            }

            $this->record($generation->user_id, $generation->id, CreditTransactionType::Hold, Money::zero()->minus($required));
        });
    }

    public function settle(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $this->release($generation);
            $this->record($generation->user_id, $generation->id, CreditTransactionType::Charge, Money::zero()->minus($generation->charged_amount ?? Money::zero()));
        });
    }

    public function release(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $hold = CreditTransaction::query()
                ->where('generation_id', $generation->id)
                ->where('type', CreditTransactionType::Hold)
                ->first();

            if ($hold !== null) {
                $this->record($generation->user_id, $generation->id, CreditTransactionType::Release, Money::fromNanos(-$hold->amount));
            }
        });
    }

    /**
     * Insert the ledger row first. The unique (generation_id, type) index turns a
     * repeated call into a no-op before the balance is touched.
     */
    private function record(int $userId, ?int $generationId, CreditTransactionType $type, Money $amount): void
    {
        $transaction = $generationId === null
            ? CreditTransaction::query()->create(['user_id' => $userId, 'type' => $type, 'amount' => $amount->nanos, 'balance_after' => 0])
            : CreditTransaction::query()->createOrFirst(
                ['generation_id' => $generationId, 'type' => $type],
                ['user_id' => $userId, 'amount' => $amount->nanos, 'balance_after' => 0],
            );

        if (! $transaction->wasRecentlyCreated) {
            return;
        }

        User::query()->whereKey($userId)->increment('credit_balance', $amount->nanos);

        $transaction->update(['balance_after' => User::query()->whereKey($userId)->value('credit_balance')]);
    }
}
```

The check-then-insert in `hold()` is safe on SQLite, because `IMMEDIATE` transactions (Phase 1, Task 0) serialize writers. On Postgres, add `->lockForUpdate()` to the balance query. Inside a transaction, `createOrFirst` relies on catching a unique violation. On Postgres that aborts the transaction, so if the DB changes, switch to `insertOrIgnore` and check the affected rows. Leave a PHPDoc note on `record()` saying this.

- [ ] **Step 5: Run the test and check it passes.** Commit: `feat: append-only credit ledger with holds`.

---

### Task 6: Enforce balances in the generation flow

**Files:**
- Modify: `app/Http/Controllers/GenerationController.php` (`store`), `app/Services/Generations/GenerationLifecycle.php` (`afterTransition`)
- Test: `tests/Feature/GenerationCreditsTest.php`

**Interfaces — Consumes:** `CreditLedger::hold|settle|release`, `InsufficientCredits`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Generations\GenerationLifecycle;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    // Seed the pricing cache exactly as in GenerationControllerTest (Phase 1, Task 7).
});

it('blocks a generation the user cannot afford', function () {
    $user = User::factory()->create(); // balance 0

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'high'])
        ->assertSessionHasErrors('credits');

    expect($user->generations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('holds the maximum charge on submit', function () {
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, Money::fromDollars(1));

    $this->actingAs($user)->post(route('generations.store'), ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'high']);

    $generation = $user->generations()->sole();
    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000 - $generation->maximum_charge->nanos);
    Queue::assertPushed(SubmitGeneration::class);
});

it('charges the actual amount when the generation succeeds and refunds the hold when it fails', function () {
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, Money::fromDollars(1));
    $ok = Generation::factory()->for($user)->processing()->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    $bad = Generation::factory()->for($user)->processing()->create(['maximum_charge' => Money::fromNanos(467_343_750)]);
    app(CreditLedger::class)->hold($ok);
    app(CreditLedger::class)->hold($bad);

    $lifecycle = app(GenerationLifecycle::class);
    $lifecycle->apply($ok, providerSnapshot($ok, 'succeeded'));   // helper from GenerationLifecycleTest; move it to tests/Pest.php
    $lifecycle->apply($ok, providerSnapshot($ok, 'succeeded'));   // duplicate
    $lifecycle->apply($bad, providerSnapshot($bad, 'failed'));

    expect($user->refresh()->credit_balance->nanos)->toBe(1_000_000_000 - 381_887_500);
});
```

Move the `providerSnapshot()` helper from `tests/Feature/GenerationLifecycleTest.php` into `tests/Pest.php` so both files share it. The same goes for a `seedPixelforgePricing()` helper that wraps the `Cache::put` from Phase 1, Task 7.

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement**

In `GenerationController::store`, wrap the create and the hold in one transaction:

```php
        try {
            $generation = DB::transaction(function () use ($request, $size, $quality, $referenceImages, $estimate, $ledger): Generation {
                $generation = $request->user()->generations()->create([/* same attributes as Phase 1 */]);
                $ledger->hold($generation);

                return $generation;
            });
        } catch (InsufficientCredits $exception) {
            throw ValidationException::withMessages([
                'credits' => "This generation can cost up to {$this->usd($exception->required)}, but your balance is {$this->usd($exception->available)}. Buy credits to continue.",
            ]);
        }

        SubmitGeneration::dispatch($generation);
```

Inject `CreditLedger $ledger` into `store`. Add a private `usd(Money $amount): string` that returns `'$'.number_format($amount->nanos / Money::NANOS_PER_DOLLAR, 4)`. This is display only, so a float is fine here.

In `GenerationLifecycle`, inject `CreditLedger` and extend `afterTransition`:

```php
    private function afterTransition(Generation $generation, GenerationStatus $target): void
    {
        match ($target) {
            GenerationStatus::Succeeded => $this->credits->settle($generation),
            GenerationStatus::Failed => $this->credits->release($generation),
            default => null,
        };

        if ($target === GenerationStatus::Succeeded) {
            StoreGenerationImage::dispatch($generation)->afterCommit();
        }
    }
```

This runs inside the compare-and-set transaction. If the ledger write fails, the status change rolls back too, and the webhook or poll retries.

- [ ] **Step 4: Run the new test and the whole suite** (Phase 1 tests must still pass). Lifecycle tests on users with no hold still pass: `settle` charges, and the balance goes negative only for pre-existing generations that were never held, so the Phase 1 tests don't assert balances. Commit: `feat: hold, settle and release credits around the generation lifecycle`.

---

### Task 7: Buy credits UI and balance everywhere

**Files:**
- Create: `app/Http/Controllers/CreditController.php`, `app/Http/Requests/PurchaseCreditsRequest.php`, `resources/js/pages/credits/Index.vue`
- Modify: `routes/web.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/pages/generations/Create.vue`, `resources/js/components/AppSidebar.vue`, `resources/js/types/auth.ts`
- Test: `tests/Feature/CreditControllerTest.php`

**Interfaces — Produces:** routes `credits.index` (GET `/credits`) and `credits.store` (POST `/credits`, body `{pack: 1|5|20}`). Shared prop `auth.credit_balance: string|null`. Page props `transactions` (paginated: type, amount, balance_after, generation_id, created_at) and `packs: list<int>`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('buys a credit pack', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credits.store'), ['pack' => 5])->assertRedirect(route('credits.index'));

    expect($user->refresh()->credit_balance->toDecimalString())->toBe('5.000000000');
});

it('rejects unknown packs', function () {
    $this->actingAs(User::factory()->create())->post(route('credits.store'), ['pack' => 1000])->assertSessionHasErrors('pack');
});

it('shares the balance with every page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('credits.index'))
        ->assertInertia(fn (Assert $page) => $page->component('credits/Index')
            ->where('auth.credit_balance', '0.000000000')
            ->where('packs', [1, 5, 20]));
});
```

- [ ] **Step 2: Run it and check it fails.**

- [ ] **Step 3: Implement**

`PurchaseCreditsRequest` rules: `'pack' => ['required', 'integer', Rule::in(CreditController::PACKS)]`.

```php
class CreditController extends Controller
{
    /** @var list<int> Fake purchase packs, in whole dollars. */
    public const array PACKS = [1, 5, 20];

    public function index(Request $request): Response
    {
        return Inertia::render('credits/Index', [
            'packs' => self::PACKS,
            'transactions' => $request->user()->creditTransactions()->latest('id')->paginate(20)->through(
                fn (CreditTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->type->value,
                    'amount' => Money::fromNanos($transaction->amount)->toDecimalString(),
                    'balance_after' => Money::fromNanos($transaction->balance_after)->toDecimalString(),
                    'generation_id' => $transaction->generation_id,
                    'created_at' => $transaction->created_at->toIso8601String(),
                ],
            ),
        ]);
    }

    public function store(PurchaseCreditsRequest $request, CreditLedger $ledger): RedirectResponse
    {
        $ledger->purchase($request->user(), Money::fromDollars((int) $request->validated('pack')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credits added.')]);

        return to_route('credits.index');
    }
}
```

Routes, inside the `auth` group: `Route::resource('credits', CreditController::class)->only(['index', 'store']);`

`HandleInertiaRequests::share`: add `'credit_balance' => $request->user()?->credit_balance->toDecimalString()` inside the existing `auth` key (follow its current structure), plus `is_admin` if it isn't already exposed.

UI:
- **Sidebar**: a "Credits" item showing the live balance, e.g. `Credits · $4.6182`, plus "Admin report" for admins.
- **`credits/Index.vue`**: a big balance figure and three pack buttons ("Add $1 / $5 / $20" with "Demo purchase, no payment taken" underneath). The ledger table has columns Date · Type · Amount (green +, red −) · Balance after. Holds and releases are labelled "Reserved for generation #id" and "Reservation returned".
- **`generations/Create.vue`**: the cost card shows "Balance $x". When `balance < estimate.maximum`, disable Generate and show "You need up to $max. Buy credits" linking to `credits.index`. Show `form.errors.credits` in an `AlertError` as a server-side fallback.

- [ ] **Step 4: Verify.** Run `php artisan test --compact` (all green), then `npm run types:check && npm run check && npm run build`. Do a manual pass: buy $1 → generate high quality → the balance drops by the max at submit, then rises back to `1 − actual charge` on success. Then try `[fail]`: the balance is fully restored.

- [ ] **Step 5: Commit** — `feat: buy credits and balance-aware generation UI`.

---

### Task 8: Production rollout

- [ ] Deploy (`docker compose up -d --build`). The migrations run in the entrypoint. Existing users start at a $0 balance.
- [ ] `docker compose exec app php artisan users:promote <operator email>`.
- [ ] Open `/admin/spend-report` and confirm every day with generations shows ✓ **Matches** against `/v1/usage`.
- [ ] Update `README.md` with an admin access note (how to promote), the ledger design (hold max → settle actual), and how the reconciliation works.

## Self-Review

- **Spec coverage:**
  - Admin report per day and per user (credit spend, provider cost, profit) → T3, T4
  - Matches `/v1/usage` → T2, T3 (per-day match flag)
  - Balances → T5
  - Buy credits → T7
  - Block when unaffordable → T6, T7
- **Phase 1 interaction:** `afterTransition` only runs after a winning compare-and-set (Phase 1 T5), and the ledger is also idempotent via its unique index. That's two independent guarantees against double charging.
- **Type consistency:**
  - `maximum_charge`, `charged_amount` and `credit_balance` (Money) are used consistently.
  - `CreditLedger` method names match between T5, T6 and T7.
