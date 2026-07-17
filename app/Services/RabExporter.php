<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\Project;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * FR-16: RAB Excel dengan formula hidup — ubah rate/margin/MD di sheet, biaya ikut terhitung ulang.
 */
class RabExporter
{
    public function generate(Project $project, Estimate $estimate): string
    {
        $roleSplit = Estimator::roleSplit();
        $sheet = ($spreadsheet = new Spreadsheet)->getActiveSheet();
        $sheet->setTitle('RAB');

        $snapshot = $estimate->rate_card_snapshot ?? [];
        $rates = collect($snapshot['roles'] ?? [])->keyBy('role');

        // Blok parameter (formula referensi ke sini → "formula hidup")
        $sheet->setCellValue('A1', 'RAB — '.$project->name.' ('.strtoupper($estimate->scope).')');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A3', 'Parameter');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $row = 4;
        foreach ($roleSplit as $role => $pct) {
            $sheet->setCellValue("A{$row}", "Rate {$role} /MD");
            $sheet->setCellValue("B{$row}", (float) ($rates[$role]['daily_rate'] ?? 0));
            $sheet->setCellValue("C{$row}", $pct);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE);
            $row++;
        }
        $marginRow = $row;
        $sheet->setCellValue("A{$marginRow}", 'Margin');
        $sheet->setCellValue("B{$marginRow}", ($snapshot['margin_pct'] ?? 0) / 100);
        $sheet->getStyle("B{$marginRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE);
        $bufferRow = $marginRow + 1;
        $sheet->setCellValue("A{$bufferRow}", 'Buffer UAT');
        $sheet->setCellValue("B{$bufferRow}", config('spekta.estimate.buffer_pct') / 100);
        $sheet->getStyle("B{$bufferRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE);

        // Blended rate per MD = Σ(rate × porsi) — sel bantu untuk formula baris
        $blendedRow = $bufferRow + 1;
        $rateStart = 4;
        $rateEnd = $rateStart + count($roleSplit) - 1;
        $sheet->setCellValue("A{$blendedRow}", 'Blended rate /MD');
        $sheet->setCellValue("B{$blendedRow}", "=SUMPRODUCT(B{$rateStart}:B{$rateEnd},C{$rateStart}:C{$rateEnd})");

        // Tabel lines
        $head = $blendedRow + 2;
        foreach (['A' => 'Modul / Fitur', 'B' => 'MD', 'C' => 'Biaya'] as $col => $label) {
            $sheet->setCellValue("{$col}{$head}", $label);
            $sheet->getStyle("{$col}{$head}")->getFont()->setBold(true);
        }

        $r = $head + 1;
        $firstLine = $r;
        foreach ($estimate->lines as $line) {
            $sheet->setCellValue("A{$r}", $line->structureNode?->title ?? '—');
            $sheet->setCellValue("B{$r}", (float) $line->md);
            $sheet->setCellValue("C{$r}", "=B{$r}*\$B\${$blendedRow}*(1+\$B\${$marginRow})");
            $r++;
        }
        $lastLine = $r - 1;

        $sheet->setCellValue("A{$r}", 'Setup, deploy, UAT & buffer');
        $sheet->setCellValue("B{$r}", "=SUM(B{$firstLine}:B{$lastLine})*\$B\${$bufferRow}");
        $sheet->setCellValue("C{$r}", "=B{$r}*\$B\${$blendedRow}*(1+\$B\${$marginRow})");
        $totalRow = $r + 1;
        $sheet->setCellValue("A{$totalRow}", 'TOTAL');
        $sheet->setCellValue("B{$totalRow}", "=SUM(B{$firstLine}:B{$r})");
        $sheet->setCellValue("C{$totalRow}", "=SUM(C{$firstLine}:C{$r})");
        $sheet->getStyle("A{$totalRow}:C{$totalRow}")->getFont()->setBold(true);

        $sheet->getStyle("B4:B{$rateEnd}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("B{$blendedRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("C{$firstLine}:C{$totalRow}")->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(20);

        $path = tempnam(sys_get_temp_dir(), 'rab').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
