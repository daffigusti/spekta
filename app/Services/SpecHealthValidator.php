<?php

namespace App\Services;

use App\Models\Project;

/**
 * FR-11 aturan inti:
 * (a) tiap FR punya acceptance criteria di REQUIREMENTS (+ minimal 1 butir AC di bawah headingnya)
 * (b) tiap FR muncul di ROADMAP
 * (c) tiap FR punya skenario di TESTING — critical untuk FR scope mvp (P0), warning sisanya
 * (d) PRD punya section Assumptions (BR-13)
 * (e) WIREFRAMES JSON valid + coverage flow vs USER_FLOWS
 * (f) DATABASE punya erDiagram
 * (g) SECURITY menyebut semua role (matrix akses lengkap)
 * (h) fact drift: angka di dokumen lain tidak menyimpang dari fakta kanonik REQUIREMENTS
 * Skor = 100 - Σ penalti (critical 15, warning 7, info 2), floor 0.
 */
class SpecHealthValidator
{
    public function run(Project $project): int
    {
        $project->healthFindings()->where('rule_key', '!=', 'contradiction')->delete();

        $docs = $project->documents()->with('currentVersion')->get()
            ->mapWithKeys(fn ($d) => [$d->doc_key => $d->currentVersion?->content_md ?? '']);

        $findings = [];
        $prd = $docs['PRD'] ?? '';
        // Terima FR-01 maupun FR-1; "FR-1.1" otomatis tereduksi ke FR top-level FR-1
        preg_match_all('/FR-\d+/', $prd, $m);
        $frs = array_unique($m[0]);

        // FR-xx → scope: penomoran FR mengikuti urutan fitur di struktur (template PRD)
        $scopeByFr = $this->scopeByFr($project);

        foreach ($frs as $fr) {
            if (isset($docs['REQUIREMENTS']) && ! $this->mentions($docs['REQUIREMENTS'], $fr)) {
                $findings[] = ['rule_key' => 'fr_has_ac', 'severity' => 'critical', 'location' => "REQUIREMENTS / $fr",
                    'message' => "$fr tidak memiliki acceptance criteria di REQUIREMENTS.md",
                    'suggestion' => "Tambahkan section $fr dengan acceptance criteria terukur."];
            } elseif (isset($docs['REQUIREMENTS']) && ! $this->hasBulletsUnderHeading($docs['REQUIREMENTS'], $fr)) {
                $findings[] = ['rule_key' => 'fr_ac_empty', 'severity' => 'critical', 'location' => "REQUIREMENTS / $fr",
                    'message' => "Section $fr di REQUIREMENTS.md tidak berisi butir acceptance criteria",
                    'suggestion' => "Isi section $fr dengan ≥3 acceptance criteria format Given/When/Then."];
            }
            if (isset($docs['ROADMAP']) && ! $this->mentions($docs['ROADMAP'], $fr)) {
                $findings[] = ['rule_key' => 'fr_in_roadmap', 'severity' => 'warning', 'location' => "ROADMAP / $fr",
                    'message' => "$fr tidak muncul di ROADMAP.md",
                    'suggestion' => "Petakan $fr ke fase & prioritas di ROADMAP."];
            }
            if (isset($docs['TESTING']) && ! $this->mentions($docs['TESTING'], $fr)) {
                $isP0 = ($scopeByFr[$fr] ?? null) === 'mvp';
                $findings[] = ['rule_key' => 'fr_has_test', 'severity' => $isP0 ? 'critical' : 'warning', 'location' => "TESTING / $fr",
                    'message' => "$fr belum punya skenario uji di TESTING.md".($isP0 ? ' (scope MVP/P0)' : ''),
                    'suggestion' => "Tambahkan skenario uji untuk $fr."];
            }
        }

        if ($prd !== '' && ! preg_match('/#+\s*[\d.\s]*(Assumptions|Asumsi)/iu', $prd)) {
            $findings[] = ['rule_key' => 'prd_assumptions', 'severity' => 'critical', 'location' => 'PRD',
                'message' => 'Tidak memiliki section Assumptions/Asumsi (wajib per BR-13)',
                'suggestion' => 'Tambahkan section Assumptions berisi seluruh asumsi proyek.'];
        }

        if (empty($frs) && $prd !== '') {
            $findings[] = ['rule_key' => 'prd_has_fr', 'severity' => 'critical', 'location' => 'PRD',
                'message' => 'Tidak berisi functional requirement ber-nomor (FR-xx)',
                'suggestion' => 'Strukturkan kebutuhan sebagai FR-01, FR-02, …'];
        }

        if (($docs['DATABASE'] ?? '') !== '' && ! str_contains($docs['DATABASE'], 'erDiagram')) {
            $findings[] = ['rule_key' => 'db_has_erd', 'severity' => 'warning', 'location' => 'DATABASE',
                'message' => 'Tidak memiliki diagram relasi (mermaid erDiagram)',
                'suggestion' => 'Tambahkan blok ```mermaid erDiagram``` yang memuat seluruh entity.'];
        }

        foreach ($this->wireframeFindings($docs['WIREFRAMES'] ?? '', $docs['USER_FLOWS'] ?? '') as $f) {
            $findings[] = $f;
        }

        // SECURITY: matrix akses wajib mencakup semua role dari understanding
        if (($security = $docs['SECURITY'] ?? '') !== '') {
            foreach ($project->understanding?->roles ?? [] as $r) {
                $name = $r['name'] ?? '';
                if ($name !== '' && stripos($security, $name) === false) {
                    $findings[] = ['rule_key' => 'security_role_coverage', 'severity' => 'warning', 'location' => "SECURITY / $name",
                        'message' => "Role \"$name\" tidak disebut di SECURITY.md — matrix akses belum lengkap",
                        'suggestion' => "Tambahkan role $name ke Matrix Akses beserta hak aksesnya."];
                }
            }
        }

        // FR-11 aturan lanjutan (d)+(e)
        foreach (self::erdApiFindings($docs['DATABASE'] ?? '', $docs['API'] ?? '') as $f) {
            $findings[] = $f;
        }
        foreach (self::numberingFindings($docs->all()) as $f) {
            $findings[] = $f;
        }

        // FR-11(h): fact drift — HANYA baca fact_sheet yang sudah ter-cache di meta (diisi
        // SpecEngine::factSheet saat generate); validator jalur sync, dilarang trigger LLM di sini.
        $facts = $project->documents()->where('doc_key', 'REQUIREMENTS')->first()
            ?->currentVersion?->generated_meta['fact_sheet'] ?? [];
        foreach (self::factDriftFindings(is_array($facts) ? $facts : [], $docs->all()) as $f) {
            $findings[] = $f;
        }

        foreach ($findings as $f) {
            $project->healthFindings()->create($f);
        }

        return $this->recomputeScore($project);
    }

    /** Skor dari seluruh findings tersimpan — termasuk kontradiksi yang diisi async. */
    public function recomputeScore(Project $project): int
    {
        $penalty = ['critical' => 15, 'warning' => 7, 'info' => 2];
        $score = 100;
        foreach ($project->healthFindings()->where('resolved', false)->get() as $f) {
            $score -= $penalty[$f->severity] ?? 0;
        }
        $score = max(0, $score);
        $project->update(['health_score' => $score]);

        return $score;
    }

    /** FR-11(d): tiap entity di erDiagram DATABASE dirujuk di API.md. Static murni — unit-testable. */
    public static function erdApiFindings(string $database, string $api): array
    {
        if ($database === '' || $api === '' || ! str_contains($database, 'erDiagram')) {
            return [];
        }
        // ponytail: entity = "nama {" di dalam blok erDiagram; regex cukup, parser mermaid berlebihan
        preg_match_all('/^\s{0,8}([A-Za-z_][A-Za-z0-9_]*)\s*\{/m', $database, $m);
        $findings = [];
        foreach (array_unique($m[1]) as $entity) {
            if (stripos($api, $entity) === false) {
                $findings[] = ['rule_key' => 'erd_entity_in_api', 'severity' => 'warning', 'location' => "DATABASE / $entity",
                    'message' => "Entity \"$entity\" di ERD tidak dirujuk di API.md",
                    'suggestion' => "Tambahkan endpoint/schema yang memakai $entity di API.md, atau hapus entity tak terpakai dari ERD."];
            }
        }

        return $findings;
    }

    /** FR-11(e): FR dirujuk harus terdefinisi di REQUIREMENTS; nomor FR tidak dobel. */
    public static function numberingFindings(array $docs): array
    {
        $req = $docs['REQUIREMENTS'] ?? '';
        if ($req === '') {
            return [];
        }
        preg_match_all('/^#{2,4}\s.*?\b(FR-\d+)\b/m', $req, $m);
        $defined = $m[1];
        $findings = [];
        foreach (array_unique(array_diff_assoc($defined, array_unique($defined))) as $dup) {
            $findings[] = ['rule_key' => 'fr_duplicate', 'severity' => 'warning', 'location' => "REQUIREMENTS / $dup",
                'message' => "$dup terdefinisi lebih dari satu kali di REQUIREMENTS.md",
                'suggestion' => 'Gabungkan section duplikat atau renumber agar tiap FR unik.'];
        }
        preg_match_all('/\bFR-\d+\b/', implode("\n", $docs), $refs);
        preg_match_all('/\bFR-\d+\b/', $docs['PRD'] ?? '', $prd); // FR di PRD sudah dijaga rule fr_has_ac
        foreach (array_diff(array_unique($refs[0]), $defined, array_unique($prd[0])) as $ref) {
            $findings[] = ['rule_key' => 'fr_dangling_ref', 'severity' => 'warning', 'location' => "REQUIREMENTS / $ref",
                'message' => "$ref dirujuk di dokumen tapi tidak terdefinisi di REQUIREMENTS.md",
                'suggestion' => "Tambahkan section $ref di REQUIREMENTS atau perbaiki rujukan yang salah nomor."];
        }

        return $findings;
    }

    /**
     * FR-11(h): angka di dokumen menyimpang dari fakta kanonik REQUIREMENTS. Deterministik, tanpa LLM.
     * Kanon = keyword → himpunan angka sah dari SEMUA fakta ("min 2 cabang" + "maks 5 cabang" → cabang:{2,5}),
     * jadi pasangan min/maks tidak false positive. Static murni — unit-testable.
     */
    public static function factDriftFindings(array $facts, array $docs): array
    {
        $canon = [];
        foreach ($facts as $fact) {
            if (! is_string($fact)) {
                continue;
            }
            foreach (self::numberKeywordPairs($fact) as [$num, $kw]) {
                $canon[$kw][$num] = true;
            }
        }
        if ($canon === []) {
            return [];
        }

        $findings = [];
        $seen = [];
        foreach ($docs as $key => $md) {
            if (in_array($key, ['REQUIREMENTS', 'WIREFRAMES']) || $md === '') {
                continue; // REQUIREMENTS = sumber kanon; WIREFRAMES = JSON layout, koordinatnya bukan klaim
            }
            // buang blok kode/mermaid — angka di dalamnya bukan klaim requirement
            $clean = preg_replace('/^```.*?^```/ms', '', $md) ?? $md;
            foreach (self::numberKeywordPairs($clean) as [$num, $kw]) {
                if (! isset($canon[$kw]) || isset($canon[$kw][$num]) || isset($seen["$key|$kw"])) {
                    continue;
                }
                $seen["$key|$kw"] = true;
                $valid = implode('/', array_keys($canon[$kw]));
                $findings[] = ['rule_key' => 'fact_drift', 'severity' => 'warning', 'location' => "$key / $kw",
                    'message' => "Angka \"$num $kw\" di $key.md menyimpang dari fakta kanonik REQUIREMENTS ($valid $kw)",
                    'suggestion' => "Samakan angka $kw di $key.md dengan REQUIREMENTS, atau perbarui REQUIREMENTS bila memang berubah."];
                if (count($findings) >= 8) {
                    return $findings; // cap — banjir temuan = noise, 8 pertama sudah cukup jadi alarm
                }
            }
        }

        return $findings;
    }

    /** Pasangan (angka, keyword terdekat) dari teks — keyword non-stopword dalam jendela 2 token, prioritas setelah angka. */
    private static function numberKeywordPairs(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}%,.]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_map(fn ($t) => trim($t, '.,'), $tokens));
        $stop = ['dan', 'atau', 'yang', 'dari', 'untuk', 'pada', 'dengan', 'per', 'tiap', 'setiap', 'paling',
            'lebih', 'kurang', 'maksimal', 'maksimum', 'minimal', 'minimum', 'maks', 'min', 'hingga', 'sampai',
            'adalah', 'harus', 'wajib', 'bila', 'jika', 'the', 'and', 'max', 'most', 'least', 'atas', 'bawah'];
        $codePrefix = ['fr', 'br', 'adr', 'v', 'versi', 'version', 'fase', 'phase', 'sprint', 'p'];

        $pairs = [];
        foreach ($tokens as $i => $t) {
            if (! preg_match('/^\d+(?:[.,]\d+)?%?$/', $t)) {
                continue;
            }
            // bukan klaim angka: kode FR-12/BR-05/v2/Fase 1, dan tahun
            if (in_array($tokens[$i - 1] ?? '', $codePrefix)) {
                continue;
            }
            $n = (int) $t;
            if ($n >= 1900 && $n <= 2100 && ! str_contains($t, '%')) {
                continue;
            }
            foreach ([$i + 1, $i + 2, $i - 1, $i - 2] as $j) {
                $kw = $tokens[$j] ?? null;
                if ($kw === null || mb_strlen($kw) < 3 || in_array($kw, $stop) || preg_match('/\d/', $kw)) {
                    continue;
                }
                $pairs[] = [rtrim($t, '%'), $kw]; // % dibuang: "20%" dan "20 persen" harus dianggap sama
                break;
            }
        }

        return $pairs;
    }

    /** FR disebut sebagai token utuh — "FR-1" tidak boleh cocok dengan "FR-10" (sub "FR-1.x" tetap dihitung). */
    private function mentions(string $md, string $fr): bool
    {
        return (bool) preg_match('/'.preg_quote($fr, '/').'(?!\d)/', $md);
    }

    /** FR-01 = fitur pertama urutan struktur, dst — konsisten dengan template PRD. Key padded & non-padded. */
    private function scopeByFr(Project $project): array
    {
        $nodes = $project->structureNodes;
        $map = [];
        $i = 1;
        foreach ($nodes->where('kind', 'phase') as $phase) {
            foreach ($nodes->where('parent_id', $phase->id) as $feature) {
                $map[sprintf('FR-%02d', $i)] = $feature->scope;
                $map['FR-'.$i++] = $feature->scope;
            }
        }

        return $map;
    }

    /** Ada ≥1 bullet/baris tabel di bawah heading yang memuat $fr, sebelum heading berikutnya. */
    private function hasBulletsUnderHeading(string $md, string $fr): bool
    {
        if (! preg_match('/^#{1,6}[^\n]*'.preg_quote($fr, '/').'(?![\d.])[^\n]*\n(.*?)(?=^#{1,6}\s|\z)/ms', $md, $m)) {
            return true; // FR disebut tapi bukan heading — jangan false positive
        }

        return (bool) preg_match('/^\s*([-*+]\s|\d+\.\s|\|)/m', $m[1]);
    }

    /** WIREFRAMES harus JSON valid; coverage flow dibanding jumlah flow USER_FLOWS. */
    private function wireframeFindings(string $wireframes, string $userFlows): array
    {
        if ($wireframes === '') {
            return [];
        }

        $json = json_decode($wireframes, true);
        if (! is_array($json) || ! isset($json['screens'])) {
            return [['rule_key' => 'wireframes_json', 'severity' => 'critical', 'location' => 'WIREFRAMES',
                'message' => 'Konten WIREFRAMES bukan JSON valid berskema {"screens":[…]} — canvas tidak bisa render',
                'suggestion' => 'Regenerate WIREFRAMES atau perbaiki via asisten chat.']];
        }

        $findings = [];
        $flowCount = count(array_unique(array_map(fn ($s) => $s['flow'] ?? '', $json['screens'])));
        $expected = preg_match_all('/^##\s*Flow\b/mi', $userFlows);
        if ($expected > 0 && $flowCount < $expected) {
            $findings[] = ['rule_key' => 'wireframes_flow_coverage', 'severity' => 'info', 'location' => 'WIREFRAMES',
                'message' => "Wireframe hanya mencakup $flowCount dari $expected flow di USER_FLOWS.md",
                'suggestion' => 'Lengkapi layar untuk flow yang belum tergambar.'];
        }

        return $findings;
    }
}
