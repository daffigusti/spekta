<?php

namespace App\Support;

/**
 * Format uang per currency — sumber tunggal untuk proposal DOCX, RAB xlsx, dan tampilan.
 * Tanpa konversi kurs: currency mengikuti snapshot rate card (FR-14).
 */
final class Money
{
    public static function symbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'IDR' => 'Rp',
            'USD' => '$',
            default => strtoupper($currency),
        };
    }

    public static function format(float $n, string $currency = 'IDR'): string
    {
        return match (strtoupper($currency)) {
            'IDR' => 'Rp '.number_format($n, 0, ',', '.'),
            'USD' => '$'.number_format($n, 0, '.', ','),
            default => strtoupper($currency).' '.number_format($n, 0, ',', '.'),
        };
    }

    /** Format number Excel (PhpSpreadsheet) untuk kolom uang. */
    public static function excelFormat(string $currency = 'IDR'): string
    {
        return match (strtoupper($currency)) {
            'IDR' => '"Rp" #,##0',
            'USD' => '"$"#,##0',
            default => '"'.strtoupper($currency).'" #,##0',
        };
    }
}
