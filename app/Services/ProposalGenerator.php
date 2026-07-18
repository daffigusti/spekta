<?php

namespace App\Services;

use App\Models\DocTemplate;
use App\Models\Estimate;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * FR-16: proposal DOCX — ringkasan eksekutif, scope, deliverables, timeline, RAB,
 * skema pembayaran, asumsi & eksklusi, syarat garansi.
 * Branding per-workspace: logo (workspaces.logo_url) + warna aksen (workspaces.brand_colors.primary);
 * skema pembayaran/garansi dari doc_templates.config, fallback config spekta.proposal.
 */
class ProposalGenerator
{
    private const TEAL = '0D9488';

    private const DARK = '111827';

    private const GRAY = '6B7280';

    private string $accent = self::TEAL;

    private ?DocTemplate $tpl = null;

    public function generate(Project $project, Estimate $estimate): string
    {
        $workspace = $project->workspace;
        $this->tpl = $project->docTemplate ?: $workspace->defaultDocTemplate();
        $this->accent = $this->resolveAccent($workspace->brand_colors['primary'] ?? null);

        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10.5);

        $word->addTitleStyle(1, ['size' => 22, 'bold' => true, 'color' => self::DARK], ['spaceAfter' => 120]);
        $word->addTitleStyle(2, ['size' => 14, 'bold' => true, 'color' => $this->accent], ['spaceBefore' => 360, 'spaceAfter' => 120]);

        $section = $word->addSection(['marginLeft' => Converter::cmToTwip(2.2), 'marginRight' => Converter::cmToTwip(2.2)]);

        $fmt = fn (float $n) => Money::format($n, $estimate->currency ?? 'IDR');

        // Sampul ringkas
        $this->maybeAddLogo($section, $workspace->logo_url);
        $section->addText($workspace->name, ['size' => 11, 'bold' => true, 'color' => $this->accent]);
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

        if (! ($this->tpl->config['white_label'] ?? false)) {
            $section->addText('Dokumen dibuat dengan Spekta', ['size' => 8, 'color' => self::GRAY, 'italic' => true], ['spaceBefore' => 360]);
        }

        $path = tempnam(sys_get_temp_dir(), 'proposal').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /** Warna aksen workspace — hanya hex 6 digit valid; selain itu fallback teal. */
    private function resolveAccent(?string $primary): string
    {
        $hex = ltrim((string) $primary, '#');

        return preg_match('/^[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : self::TEAL;
    }

    /** Bg header tabel: tint teal hanya untuk aksen default; aksen custom pakai netral. */
    private function headerBg(): string
    {
        return $this->accent === self::TEAL ? 'F0FDFA' : 'F3F4F6';
    }

    private function maybeAddLogo(Section $s, ?string $logoUrl): void
    {
        if (! $logoUrl) {
            return;
        }
        $path = Storage::disk('public')->path(Str::after($logoUrl, '/storage/'));
        if (! is_file($path)) {
            return;
        }
        try {
            $s->addImage($path, ['height' => 30]);
        } catch (\Throwable) {
            // file logo korup/format tak didukung PhpWord — proposal tetap jalan tanpa logo
        }
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
            $table->addCell(null, ['bgColor' => $this->headerBg()])->addText($h, ['bold' => true, 'size' => 9, 'color' => $this->accent]);
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
            $table->addCell(null, ['bgColor' => $this->headerBg()])->addText($h, ['bold' => true, 'size' => 9, 'color' => $this->accent]);
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
        $table->addCell(null, ['bgColor' => $this->headerBg()])->addText('TOTAL', ['bold' => true, 'size' => 10]);
        $table->addCell(null, ['bgColor' => $this->headerBg()])->addText((string) $estimate->total_md, ['bold' => true, 'size' => 10], ['alignment' => Jc::CENTER]);
        $table->addCell(null, ['bgColor' => $this->headerBg()])->addText($fmt($estimate->total_cost), ['bold' => true, 'size' => 10, 'color' => $this->accent], ['alignment' => Jc::END]);

        $s->addText('Rentang estimasi ±'.$estimate->range_pct.'% mengikuti tingkat kepastian requirement saat ini.', ['size' => 9, 'color' => self::GRAY, 'italic' => true]);
    }

    private function paymentScheme(Section $s, Estimate $estimate, callable $fmt): void
    {
        $s->addTitle('6. Skema Pembayaran', 2);
        // Override per-template (doc_templates.config.payment_scheme), fallback default config
        $scheme = $this->tpl->config['payment_scheme'] ?? config('spekta.proposal.payment_scheme');
        foreach ($scheme as $row) {
            $s->addListItem($row['label'].': '.$row['pct'].'% — '.$fmt($estimate->total_cost * $row['pct'] / 100), 0);
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
        $days = (int) ($this->tpl->config['warranty_days'] ?? config('spekta.proposal.warranty_days'));
        $s->addTitle('8. Syarat Garansi', 2);
        $s->addText(
            "Garansi perbaikan bug {$days} hari kalender sejak serah terima, mencakup defect terhadap acceptance criteria "
            .'yang disepakati. Tidak termasuk permintaan fitur baru atau perubahan requirement.',
            [],
            ['lineHeight' => 1.4]
        );
    }
}
