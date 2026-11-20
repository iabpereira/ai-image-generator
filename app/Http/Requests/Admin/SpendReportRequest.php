<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SpendReportRequest extends FormRequest
{
    /** Pixelforge's /v1/usage accepts at most this many days per request. */
    public const int MAX_DAYS = 92;

    private const int DEFAULT_DAYS = 7;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAdminReport') ?? false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->startDate()->diffInDays($this->endDate()) >= self::MAX_DAYS) {
                    $fail('The range can cover at most '.self::MAX_DAYS.' days.');
                }
            }],
        ];
    }

    public function startDate(): CarbonImmutable
    {
        $start = $this->input('start_date');

        return is_string($start)
            ? CarbonImmutable::parse($start, 'UTC')
            : $this->endDate()->subDays(self::DEFAULT_DAYS - 1);
    }

    public function endDate(): CarbonImmutable
    {
        $end = $this->input('end_date');

        return is_string($end) ? CarbonImmutable::parse($end, 'UTC') : CarbonImmutable::now('UTC')->startOfDay();
    }
}
