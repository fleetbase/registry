<?php

namespace App\Analytics;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A stats report's series as CSV: one row per day, week or month, with gross, fee and net
 * columns per currency the range sold in (major units).
 */
class StatsCsv
{
    public static function download(array $report, string $name): StreamedResponse
    {
        $currencies = collect($report['totals']['revenue'])->pluck('currency')->sort()->values()->all();

        return response()->streamDownload(function () use ($report, $currencies) {
            $out = fopen('php://output', 'w');
            $header = ['period', 'installs', 'updates', 'uninstalls', 'purchases'];
            foreach ($currencies as $currency) {
                array_push($header, "gross_{$currency}", "fee_{$currency}", "net_{$currency}");
            }
            fputcsv($out, $header);

            foreach ($report['series'] as $row) {
                $line = [$row['date'], $row['installs'], $row['updates'], $row['uninstalls'], $row['purchases']];
                $revenue = collect($row['revenue'])->keyBy('currency');
                foreach ($currencies as $currency) {
                    $money = $revenue->get($currency, ['gross' => 0, 'fee' => 0, 'net' => 0]);
                    array_push($line, static::decimal($money['gross']), static::decimal($money['fee']), static::decimal($money['net']));
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * `{prefix}-{from}-to-{to}-{group}.csv`
     */
    public static function filename(string $prefix, StatsRange $range): string
    {
        return "{$prefix}-stats-{$range->from->toDateString()}-to-{$range->to->toDateString()}-{$range->group}.csv";
    }

    protected static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
