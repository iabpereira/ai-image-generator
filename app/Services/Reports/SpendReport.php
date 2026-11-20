<?php

namespace App\Services\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Credit spend, provider cost and profit per UTC day and per user, reconciled
 * against Pixelforge's GET /v1/usage.
 *
 * Generations are counted on the UTC day they completed, which is how Pixelforge
 * bills; only succeeded generations cost anything.
 */
final readonly class SpendReport
{
    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array{
     *     rows: list<array{date: string, user_id: int, user_name: string, user_email: string, generations: int, charged: string, provider_cost: string, profit: string}>,
     *     days: list<array{date: string, generations: int, charged: string, provider_cost: string, profit: string, pixelforge_cost: string|null, matches: bool|null}>,
     *     totals: array{generations: int, charged: string, provider_cost: string, profit: string},
     *     usage_error: string|null
     * }
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
            ->map(fn (object $row): array => [
                'date' => (string) $row->day,
                'user_id' => (int) $row->user_id,
                'user_name' => (string) $row->user_name,
                'user_email' => (string) $row->user_email,
                'generations' => (int) $row->generations,
                'charged_nanos' => (int) $row->charged,
                'provider_nanos' => (int) $row->provider_cost,
            ]);

        [$pixelforge, $usageError] = $this->pixelforgeUsage($start, $end);

        $days = [];

        for ($date = $start->startOfDay(); $date->lte($end); $date = $date->addDay()) {
            $day = $date->toDateString();
            $dayRows = $rows->where('date', $day);
            $provider = (int) $dayRows->sum('provider_nanos');
            $theirs = $pixelforge[$day] ?? null;

            $days[] = [
                'date' => $day,
                ...$this->amounts((int) $dayRows->sum('generations'), (int) $dayRows->sum('charged_nanos'), $provider),
                'pixelforge_cost' => $theirs?->toDecimalString(),
                'matches' => $theirs === null ? null : $theirs->nanos === $provider,
            ];
        }

        return [
            'rows' => array_values($rows->map(fn (array $row): array => [
                'date' => $row['date'],
                'user_id' => $row['user_id'],
                'user_name' => $row['user_name'],
                'user_email' => $row['user_email'],
                ...$this->amounts($row['generations'], $row['charged_nanos'], $row['provider_nanos']),
            ])->all()),
            'days' => $days,
            'totals' => $this->amounts((int) $rows->sum('generations'), (int) $rows->sum('charged_nanos'), (int) $rows->sum('provider_nanos')),
            'usage_error' => $usageError,
        ];
    }

    /**
     * @return array{generations: int, charged: string, provider_cost: string, profit: string}
     */
    private function amounts(int $generations, int $chargedNanos, int $providerNanos): array
    {
        return [
            'generations' => $generations,
            'charged' => Money::fromNanos($chargedNanos)->toDecimalString(),
            'provider_cost' => Money::fromNanos($providerNanos)->toDecimalString(),
            'profit' => Money::fromNanos($chargedNanos - $providerNanos)->toDecimalString(),
        ];
    }

    /**
     * @return array{0: array<string, Money>, 1: string|null}
     */
    private function pixelforgeUsage(CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            return [$this->client->usage($start, $end), null];
        } catch (PixelforgeException $exception) {
            report($exception);

            return [[], "Pixelforge usage is unavailable right now ({$exception->errorCode})."];
        }
    }
}
