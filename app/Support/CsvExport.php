<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a query as CSV in chunks, so large exports do not load every row
 * into memory.
 */
class CsvExport
{
    /**
     * @param array<string, callable(mixed): mixed> $columns header => value getter
     */
    public static function stream(Builder $query, array $columns, string $filename, int $chunk = 500): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $columns, $chunk) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
            fputcsv($out, array_keys($columns));

            $query->chunk($chunk, function ($rows) use ($out, $columns) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn (callable $get) => self::clean($get($row)), array_values($columns)));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formulas in user-entered text (CSV injection). */
    private static function clean(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        $text = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
    }
}
