<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\Project;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * FR-16: proposal DOCX — ringkasan eksekutif, scope, deliverables, timeline, RAB,
 * skema pembayaran, asumsi & eksklusi, syarat garansi.
 * ponytail: template default satu gaya (teal). Logo/warna per-workspace = fitur Template Perusahaan, nanti.
 */
class ProposalGenerator
{
    private const TEAL = '0D9488';
    private const DARK = '111827';
    private const GRAY = '6B7280';

    public function generate(Project $project, Estimate $estimate): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10.5);

        $word->addTitleStyle(1, ['size' => 22, 'bold' => true, 'color' => self::DARK], ['spaceAfter' => 120]);
        $word->addTitleStyle(2, ['size' => 14, 'bold' => true, 'color' => self::TEAL], ['spaceBefore' => 360, 'spaceAfter' => 120]);

        $section = $word->addSection(['marginLeft' => Converter::cmToTwip(2.2), 'marginRight' => Converter::cmToTwip(2.2)]);

        $workspace = $project->workspace;
        $fmt = fn (float $n) => 'Rp '.number_format($n, 0, ',', '.');

        // Sampul ringkas
        $section->addText($workspace->name, ['size' => 11, 'bold' => true, 'color' => self::TEAL]);
        $section->addTitle('Proposal: '.$project->name, 1);
        $section->addText(
            'Untuk: '.($project->client_name ?: 'Klien').' · '.now()->translatedFormat('d F Y'),
            ['color' => self::GRAY]
        );

        $this->executiveSummary($section, $project, $estimate, $fmt);
        $this->scope($section, $project, $estimate);
        $this->deliverables($section, $project);
        $this->timeline($section, $estimate);
        $this->rab($section, $estimate, $fmt);
        $this->paymentScheme($section, $estimate, $fmt);
        $this->assumptions($section, $project);
        $this->warranty($section);

        $path = tempnam(sys_get_temp_dir(), 'proposal').'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    private function executiveSummary(Section $s, Project $project, Estimate $estimate, callable $fmt): void
    {
        $u = $project->understanding;
        $featureCount = $project->structureNodes()->where('kind', 'feature')->where('scope', '!=', 'parked')->count();

        $s->addTitle('1. Ringkasan Eksekutif', 2);
        $s->addText(sprintf(
            '%s adalah %s dengan %d fitur utama dalam %d fase pengembangan. '
            .'Estimasi total effort %s man-days (±%d%%), durasi %s minggu, dengan nilai investasi %s (scope %s).',
            $project->name,
            $u?->domain ? 'solusi digital di domain '.$u->domain : 'solusi digital',
            $featureCount,
            $project->structureNodes()->where('kind', 'phase')->count(),
            $estimate->total_md,
            $estimate->range_pct,
            $estimate->duration_weeks,
            $fmt($estimate->total_cost),
            strtoupper($estimate->scope),
        ), [], ['lineHeight' => 1.4]);
    }

    private function scope(Section $s, Project $project, Estimate $estimate): void
    {
        $s->addTitle('2. Ruang Lingkup', 2);
        $nodes = $project->structureNodes;
        foreach ($nodes->where('kind', 'phase')->sortBy('phase_no') as $phase) {
            $s->addText('Fase '.($phase->phase_no ?? '').' — '.$phase->title, ['bold' => true, 'size' => 11]);
            $features = $nodes->where('parent_id', $phase->id)->where('kind', 'feature')
                ->filter(fn ($f) => $f->scope !== 'parked' && ($estimate->scope === 'full' || $f->scope === 'mvp'));
            foreach ($features as $f) {
                $subs = $nodes->where('parent_id', $f->id)->pluck('title')->implode(', ');
                $s->addListItem($f->title.($subs ? ' — '.$subs : ''), 0, [], null, ['spaceAfter' => 40]);
            }
        }
    }

    private function deliverables(Section $s, Project $project): void
    {
        $s->addTitle('3. Deliverables', 2);
        $s->addListItem('Aplikasi sesuai ruang lingkup, ter-deploy di environment produksi', 0);
        $s->addListItem('Dokumentasi teknis lengkap ('.$project->documents()->count().' dokumen: PRD, arsitektur, API, database, testing)', 0);
        $s->addListItem('Source code + akses repository penuh', 0);
        $s->addListItem('Sesi handover & training penggunaan', 0);
    }

    private function timeline(Section $s, Estimate $estimate): void
    {
        $s->addTitle('4. Timeline', 2);
        $table = $s->addTable(['borderSize' => 4, 'borderColor' => 'E5E7EB', 'cellMargin' => 90, 'width' => 100 * 50, 'unit' => 'pct']);
        $table->addRow();
        foreach (['Tahap', 'Mulai (minggu)', 'Durasi (minggu)', 'Effort (MD)'] as $h) {
            $table->addCell(null, ['bgColor' => 'F0FDFA'])->addText($h, ['bold' => true, 'size' => 9, 'color' => self::TEAL]);
        }
        foreach ($estimate->timeline ?? [] as $t) {
            $table->addRow();
            $table->addCell()->addText($t['label'], ['size' => 9.5]);
            $table->addCell()->addText('Minggu '.($t['start_week'] + 1), ['size' => 9.5], ['alignment' => Jc::CENTER]);
            $table->addCell()->addText((string) $t['weeks'], ['size' => 9.5], ['alignment' => Jc::CENTER]);
            $table->addCell()->addText((string) $t['md'], ['size' => 9.5], ['alignment' => Jc::CENTER]);
        }
    }

    private function rab(Section $s, Estimate $estimate, callable $fmt): void
    {
        $s->addTitle('5. Rencana Anggaran Biaya (RAB)', 2);
        $table = $s->addTable(['borderSize' => 4, 'borderColor' => 'E5E7EB', 'cellMargin' => 90, 'width' => 100 * 50, 'unit' => 'pct']);
        $table->addRow();
        foreach (['Modul / Fitur', 'Effort (MD)', 'Biaya'] as $h) {
            $table->addCell(null, ['bgColor' => 'F0FDFA'])->addText($h, ['bold' => true, 'size' => 9, 'color' => self::TEAL]);
        }
        foreach ($estimate->lines as $line) {
            $table->addRow();
            $table->addCell()->addText($line->structureNode?->title ?? '—', ['size' => 9.5]);
            $table->addCell()->addText((string) $line->md, ['size' => 9.5], ['alignment' => Jc::CENTER]);
            $table->addCell()->addText($fmt((float) $line->cost), ['size' => 9.5], ['alignment' => Jc::END]);
        }
        $bufferMd = round($estimate->total_md - (float) $estimate->lines->sum('md'), 1);
        $bufferCost = $estimate->total_cost - (float) $estimate->lines->sum('cost');
        $table->addRow();
        $table->addCell()->addText('Setup, deploy, UAT & buffer ('.config('spekta.estimate.buffer_pct').'%)', ['size' => 9.5, 'italic' => true]);
        $table->addCell()->addText((string) $bufferMd, ['size' => 9.5], ['alignment' => Jc::CENTER]);
        $table->addCell()->addText($fmt($bufferCost), ['size' => 9.5], ['alignment' => Jc::END]);
        $table->addRow();
        $table->addCell(null, ['bgColor' => 'F0FDFA'])->addText('TOTAL', ['bold' => true, 'size' => 10]);
        $table->addCell(null, ['bgColor' => 'F0FDFA'])->addText((string) $estimate->total_md, ['bold' => true, 'size' => 10], ['alignment' => Jc::CENTER]);
        $table->addCell(null, ['bgColor' => 'F0FDFA'])->addText($fmt($estimate->total_cost), ['bold' => true, 'size' => 10, 'color' => self::TEAL], ['alignment' => Jc::END]);

        $s->addText('Rentang estimasi ±'.$estimate->range_pct.'% mengikuti tingkat kepastian requirement saat ini.', ['size' => 9, 'color' => self::GRAY, 'italic' => true]);
    }

    private function paymentScheme(Section $s, Estimate $estimate, callable $fmt): void
    {
        $s->addTitle('6. Skema Pembayaran', 2);
        // ponytail: skema default 30/40/30 — konfigurasi per-workspace nanti
        foreach ([['Down payment (mulai kerja)', 0.30], ['Progress (selesai fase inti)', 0.40], ['Pelunasan (UAT & serah terima)', 0.30]] as [$label, $pct]) {
            $s->addListItem($label.': '.($pct * 100).'% — '.$fmt($estimate->total_cost * $pct), 0);
        }
    }

    private function assumptions(Section $s, Project $project): void
    {
        $s->addTitle('7. Asumsi & Eksklusi', 2);
        $s->addText('Asumsi:', ['bold' => true, 'size' => 10.5]);
        $assumptions = $project->assumptions();
        foreach ($assumptions ?: ['Requirement mengikuti dokumen spesifikasi terlampir.'] as $a) {
            $s->addListItem($a, 0);
        }
        $s->addText('Eksklusi:', ['bold' => true, 'size' => 10.5]);
        foreach ([
            'Biaya infrastruktur (server, domain, lisensi pihak ketiga) ditanggung klien.',
            'Perubahan scope setelah approval mengikuti mekanisme Change Request.',
            'Migrasi data di luar yang disebut dalam ruang lingkup.',
        ] as $e) {
            $s->addListItem($e, 0);
        }
    }

    private function warranty(Section $s): void
    {
        $s->addTitle('8. Syarat Garansi', 2);
        $s->addText(
            'Garansi perbaikan bug 30 hari kalender sejak serah terima, mencakup defect terhadap acceptance criteria '
            .'yang disepakati. Tidak termasuk permintaan fitur baru atau perubahan requirement.',
            [],
            ['lineHeight' => 1.4]
        );
    }
}
