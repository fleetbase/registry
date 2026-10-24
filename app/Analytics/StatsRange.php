<?php

namespace App\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The dates a stats report covers: whole UTC days from `from` to `to` (inclusive), grouped by
 * day, week (Monday to Sunday) or month, and the period of the same length just before it,
 * which the report compares against.
 */
final class StatsRange
{
    public const GROUPS = ['day', 'week', 'month'];

    /** Days a report covers when no range is given. */
    public const DEFAULT_DAYS = 30;

    /** The longest range one report covers, in days. */
    public const MAX_DAYS = 731;

    public function __construct(public readonly Carbon $from, public readonly Carbon $to, public readonly string $group) {}

    /**
     * The range from `from`, `to` and `group` in the query (validated). Without dates, the last
     * 30 days including today; without a group, days up to 92 days, weeks up to a year, else months.
     */
    public static function fromRequest(Request $request): static
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'group' => ['nullable', 'string', 'in:'.implode(',', self::GROUPS)],
        ]);

        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : $to->copy()->subDays(self::DEFAULT_DAYS - 1)->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'The start of the range must be on or before its end.']);
        }

        $range = new self($from, $to, 'day');
        if ($range->days() > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'A report covers at most '.self::MAX_DAYS.' days.']);
        }

        return new self($from, $to, $request->query('group') ?: self::defaultGroup($range->days()));
    }

    public static function defaultGroup(int $days): string
    {
        return match (true) {
            $days <= 92 => 'day',
            $days <= 366 => 'week',
            default => 'month',
        };
    }

    public function days(): int
    {
        return (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    /**
     * The period of the same number of days that ends the day before this one starts.
     */
    public function previous(): static
    {
        $to = $this->from->copy()->subDay()->endOfDay();

        return new static($to->copy()->subDays($this->days() - 1)->startOfDay(), $to, $this->group);
    }

    /**
     * Whether the range reaches today, so "active now" is its closing figure.
     */
    public function isCurrent(): bool
    {
        return $this->to->greaterThanOrEqualTo(now());
    }

    /**
     * The first day of the bucket a day (`Y-m-d`) falls in.
     */
    public function bucket(string $day): string
    {
        $date = Carbon::parse($day);

        return match ($this->group) {
            'week' => $date->startOfWeek(Carbon::MONDAY)->toDateString(),
            'month' => $date->startOfMonth()->toDateString(),
            default => $date->toDateString(),
        };
    }

    /**
     * Every bucket in the range, oldest first. The first and last may be partial weeks or months.
     *
     * @return list<string>
     */
    public function buckets(): array
    {
        $buckets = [];
        $cursor = Carbon::parse($this->bucket($this->from->toDateString()));
        $last = $this->to->toDateString();

        while ($cursor->toDateString() <= $last) {
            $buckets[] = $cursor->toDateString();
            match ($this->group) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    /**
     * @return array{from: string, to: string, days: int}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'days' => $this->days()];
    }
}
