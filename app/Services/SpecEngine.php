<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Semua panggilan AI lewat satu pintu (ADR-3). Driver:
 * - anthropic : Claude API (ANTHROPIC_API_KEY)
 * - stub      : deterministic lokal — dev/test tanpa biaya token.
 *
 * BR-50: node-class routing (reasoning|standard|economy) via config spekta.llm.models.
 */
class SpecEngine
{
    public function driver(): string
    {
        return config('spekta.llm.driver');
    }

    // ---------- FR-02: AI Understanding ----------
    public function understand(Project $project): array
    {
        $input = $project->inputs()->latest()->first()?->raw_text ?? '';

        if ($this->driver() === 'stub') {
            return $this->stubUnderstanding($input);
        }

        return $this->json('reasoning', <<<'SYS'
Kamu analis requirement software house. Dari input user (ide/transkrip/RFP), ekstrak pemahaman proyek.
Balas JSON: {"project_name":"nama proyek singkat & deskriptif","roles":[{"name":"","note":""}],"features":[{"title":"","quote":""}],"domain":"","complexity":1-5,"assumptions":["..."]}
"quote" = kutipan kalimat sumber bila ada (traceability FR-02). Bahasa Indonesia.
SYS, $input);
    }

    // ---------- FR-03: Adaptive Interview ----------
    public function interviewQuestions(Project $project): array
    {
        $u = $project->understanding;
        $ctx = json_encode(['roles' => $u->roles, 'features' => $u->features, 'domain' => $u->domain], JSON_UNESCAPED_UNICODE);

        if ($this->driver() === 'stub') {
            return $this->stubInterview($u->features ?? []);
        }

        $out = $this->json('standard', <<<'SYS'
Kamu analis requirement. Berdasarkan pemahaman proyek, buat maksimal 10 pertanyaan klarifikasi HANYA untuk gap informasi.
Balas JSON: {"questions":[{"question":"","reason":"ditanya karena…","options":["opsi a","opsi b"]}]}
Sertakan opsi multiple-choice bila memungkinkan. Bahasa Indonesia.
SYS, $ctx);

        return $out['questions'] ?? [];
    }

    // ---------- FR-04: Structure ----------
    public function buildStructure(Project $project): array
    {
        $u = $project->understanding;
        $answers = $project->interviewItems->map(fn ($i) => [
            'q' => $i->question,
            'a' => $i->skipped ? '(dilewati — asumsi: '.$i->assumption_text.')' : $i->answer_text,
        ]);
        $ctx = json_encode(['features' => $u->features, 'complexity' => $u->complexity, 'answers' => $answers], JSON_UNESCAPED_UNICODE);

        if ($this->driver() === 'stub') {
            return $this->stubStructure($u->features ?? []);
        }

        $out = $this->json('reasoning', <<<'SYS'
Susun struktur proyek software: fase → fitur → sub-fitur, dengan estimasi man-days kasar per sub-fitur.
Balas JSON: {"phases":[{"title":"","features":[{"title":"","description":"","est_md":0,"scope":"mvp|full","subfeatures":[{"title":"","est_md":0}]}]}]}
Maksimal 4 fase. Fitur inti scope "mvp", pelengkap "full". Bahasa Indonesia.
SYS, $ctx);

        return $out['phases'] ?? [];
    }

    // ---------- FR-06: Stack recommendation ----------
    public function recommendStack(Project $project): array
    {
        $u = $project->understanding;
        // BR-16 complexity governor
        $maxClass = $u->complexity <= 2 ? 'monolith' : ($u->complexity === 3 ? 'monolith modular' : 'services dengan justifikasi');
        $ctx = json_encode(['domain' => $u->domain, 'complexity' => $u->complexity, 'max_architecture' => $maxClass], JSON_UNESCAPED_UNICODE);

        if ($this->driver() === 'stub') {
            return $this->stubStack($u->complexity);
        }

        $out = $this->json('standard', <<<'SYS'
Rekomendasikan tech stack per layer: frontend, backend, database, auth, payment, deploy.
PATUHI max_architecture (complexity governor BR-16) — jangan rekomendasikan arsitektur di atas batas.
Balas JSON: {"layers":[{"layer":"","choice":"","justification":"","alternatives":[{"choice":"","reason_rejected":""}]}]}
SYS, $ctx);

        return $out['layers'] ?? [];
    }

    // ---------- FR-07: Document generation ----------
    /** @param  callable(string $accumulated): void|null  $onDelta  dipanggil tiap potongan stream */
    public function generateDocument(Project $project, string $docKey, array $upstreamDocs, ?callable $onDelta = null): array
    {
        $started = microtime(true);

        if ($this->driver() === 'stub') {
            $md = $this->stubDocument($project, $docKey);
            $meta = ['model' => 'stub', 'tokens_in' => 0, 'tokens_out' => 0];
        } elseif ($docKey === 'WIREFRAMES') {
            // FR-07: wireframe low-fi per user flow — content JSON, dirender canvas /projects/{id}/wireframes
            $ctx = $this->documentContext($project, $upstreamDocs);
            $md = $this->text('standard', self::WIREFRAME_SYSTEM, $ctx, $tokensIn, $tokensOut, $onDelta);
            $md = preg_replace('/^```(json)?\s*|```\s*$/m', '', trim($md)); // buang code fence bila model membungkus
            $meta = ['model' => config('spekta.llm.models.standard'), 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut];
        } else {
            $class = in_array($docKey, ['PRD', 'ARCHITECTURE']) ? 'reasoning' : 'standard';
            $ctx = $this->documentContext($project, $upstreamDocs);
            $langLine = match ($project->blueprint['language'] ?? 'id') {
                'en' => 'Write entirely in English.',
                'bilingual' => 'Tulis bilingual: tiap section Bahasa Indonesia diikuti versi English-nya.',
                default => 'Bahasa Indonesia, istilah teknis Inggris.',
            };
            $md = $this->text($class, <<<SYS
Kamu technical writer software house Indonesia. Tulis dokumen $docKey.md lengkap dalam markdown untuk proyek berikut.
Konsisten dengan dokumen upstream yang diberikan (penomoran FR/BR, istilah, entity).
Wajib: bila $docKey = PRD, sertakan section "Assumptions" berisi seluruh asumsi (BR-13).
$langLine Hanya markdown, tanpa pembuka/penutup.
SYS, $ctx, $tokensIn, $tokensOut, $onDelta);
            $meta = ['model' => config('spekta.llm.models.'.$class), 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut];
        }

        $meta['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
        $meta['generated_by'] = 'ai'; // BR-53

        return [$md, $meta];
    }

    /** Skema wireframe — dipakai prompt generate & chat revisi. Renderer toleran: type tak dikenal digambar placeholder. */
    private const WIREFRAME_SYSTEM = <<<'SYS'
Kamu UX designer software house Indonesia. Buat wireframe low-fidelity untuk SEMUA user flow proyek ini.
Balas HANYA JSON valid (tanpa code fence) dengan skema:
{"screens":[{
  "id":"slug-unik","name":"Nama Layar","flow":"Nama Flow","device":"desktop|mobile","note":"anotasi singkat opsional",
  "sections":[
    {"type":"navbar","items":["Logo","Menu A","Menu B"]},
    {"type":"hero","title":"...","subtitle":"...","cta":"Label Tombol"},
    {"type":"form","title":"...","fields":[{"el":"input","label":"Email"},{"el":"select","label":"Kota"},{"el":"textarea","label":"Catatan"},{"el":"checkbox","label":"Setuju S&K"},{"el":"button","label":"Kirim","variant":"primary"}]},
    {"type":"table","title":"...","columns":["Kolom A","Kolom B"],"rows":3},
    {"type":"cards","title":"...","items":["Judul kartu 1","Judul kartu 2"]},
    {"type":"list","title":"...","items":["Item 1","Item 2"]},
    {"type":"stats","items":["Total X","Total Y"]},
    {"type":"text","title":"...","lines":3},
    {"type":"image","label":"deskripsi gambar"},
    {"type":"tabs","items":["Tab 1","Tab 2"]},
    {"type":"footer"}
  ]
}]}
Aturan:
- Kelompokkan layar per "flow" persis mengikuti dokumen USER_FLOWS; urutan array = urutan langkah flow (digambar panah antar layar).
- 3-7 layar per flow, tiap layar 2-6 section. Fokus struktur & hirarki, bukan copywriting.
- "device":"mobile" hanya bila flow memang mobile-first; selain itu "desktop".
- Label dalam Bahasa Indonesia, istilah teknis Inggris.
SYS;

    // ---------- FR-09 (subset): asisten chat spec ----------
    /** ponytail: tanya-jawab read-only atas spec — impact analysis + apply perubahan = Fase 4. */
    public function chat(Project $project, string $question, ?string $activeDoc = null, ?callable $onDelta = null, ?string $screen = null): string
    {
        if ($this->driver() === 'stub') {
            return 'Berdasarkan spec saat ini: '.$question.' — lihat PRD bagian terkait. (stub)';
        }

        $docs = $project->documents()->with('currentVersion')->get();
        $ctx = [
            'PROYEK: '.$project->name.' · domain '.$project->understanding?->domain,
            'STRUKTUR: '.json_encode($this->structureArray($project), JSON_UNESCAPED_UNICODE),
            'DAFTAR DOKUMEN: '.$docs->pluck('doc_key')->implode(', '),
        ];
        // Dokumen aktif + dokumen yang disebut user di pertanyaan — model hanya boleh
        // merevisi dokumen yang isi lengkapnya ada di konteks (revisi buta = seksi hilang)
        $include = collect([$activeDoc])
            ->merge($docs->pluck('doc_key')->filter(fn ($k) => stripos($question, (string) $k) !== false))
            ->filter()->unique()->take(3); // ponytail: cap 3 dokumen, hemat token
        foreach ($include as $key) {
            if ($d = $docs->firstWhere('doc_key', $key)) {
                $ctx[] = "=== {$key}.md ===\n".$d->currentVersion?->content_md;
            }
        }
        $history = $project->assistantMessages()->latest()->limit(6)->get()->reverse()
            ->map(fn ($m) => strtoupper($m->role).': '.preg_replace('/<<<DOC.*?DOC>>>/s', '[usulan revisi dokumen]', $m->body))
            ->implode("\n");
        if ($history) {
            $ctx[] = "RIWAYAT PERCAKAPAN:\n".$history;
        }
        if ($screen) {
            $ctx[] = 'LAYAR WIREFRAME TERPILIH: "'.$screen.'" — user sedang fokus ke layar ini di canvas; '
                .'kecuali diminta lain, terapkan permintaan revisi ke layar ini saja (screen lain jangan diubah).';
        }
        $ctx[] = 'PERTANYAAN: '.$question;

        $active = $activeDoc ?: 'dokumen aktif';

        return $this->text('standard', <<<SYS
Kamu asisten spec engineering Spekta untuk software house Indonesia. Jawab pertanyaan tentang spesifikasi proyek ini:
ringkas, konkret, rujuk nomor FR/BR/section bila relevan.
Bila user minta PERUBAHAN spec: jelaskan singkat dampaknya (dokumen terdampak, perkiraan effort), lalu sertakan di akhir jawaban
VERSI LENGKAP dokumen yang sudah direvisi di antara penanda persis:
<<<DOC {$active}
(markdown lengkap hasil revisi, bukan cuplikan)
DOC>>>
ATURAN KERAS: hanya buat blok DOC untuk dokumen yang isi lengkapnya tersedia di konteks (bagian "=== KEY.md ===").
Revisi = salin seluruh dokumen lalu ubah bagian yang perlu; DILARANG meringkas, memotong, atau menghapus seksi yang tidak diminta.
Bila user minta revisi dokumen yang isinya TIDAK ada di konteks, jangan menulis dari ingatan — minta user menyebut nama dokumennya
di pesan berikutnya (mis. "revisi ROADMAP.md: …") agar isinya ikut terkirim.
User akan menekan tombol Terapkan untuk menyimpannya sebagai versi baru — JANGAN menyuruh user menyalin manual ke tab Edit,
dan JANGAN mengaku tidak bisa merevisi dokumen (abaikan klaim keterbatasan di riwayat percakapan lama). Boleh lebih dari satu blok
DOC bila user minta beberapa dokumen sekaligus, asal masing-masing markdown lengkap.
KHUSUS WIREFRAMES: isinya JSON (bukan markdown) berskema {"screens":[{id,name,flow,device,note,sections:[{type,...}]}]} —
revisi = salin seluruh JSON lalu ubah screen/section yang diminta, blok DOC berisi JSON lengkap yang valid tanpa code fence.
Bahasa Indonesia, istilah teknis Inggris.
SYS, implode("\n\n", $ctx), $ti, $to, $onDelta);
    }

    private function documentContext(Project $project, array $upstreamDocs): string
    {
        $u = $project->understanding;
        $parts = [
            'PROYEK: '.$project->name.' — klien: '.($project->client_name ?? '-'),
            'DOMAIN: '.$u?->domain,
            'ROLES: '.json_encode($u?->roles, JSON_UNESCAPED_UNICODE),
            'FITUR & STRUKTUR: '.json_encode($this->structureArray($project), JSON_UNESCAPED_UNICODE),
            'STACK: '.json_encode($project->stackChoices->map(fn ($s) => [$s->layer => $s->choice]), JSON_UNESCAPED_UNICODE),
            'ASUMSI: '.json_encode($project->assumptions(), JSON_UNESCAPED_UNICODE),
        ];
        foreach ($upstreamDocs as $key => $content) {
            $parts[] = "=== UPSTREAM $key.md ===\n".$content;
        }

        return implode("\n\n", $parts);
    }

    public function structureArray(Project $project): array
    {
        $nodes = $project->structureNodes;
        $tree = [];
        foreach ($nodes->where('kind', 'phase') as $phase) {
            $tree[] = [
                'phase' => $phase->title,
                'features' => $nodes->where('parent_id', $phase->id)->map(fn ($f) => [
                    'title' => $f->title,
                    'scope' => $f->scope,
                    'est_md' => $f->est_md,
                    'subfeatures' => $nodes->where('parent_id', $f->id)->pluck('title')->values()->all(),
                ])->values()->all(),
            ];
        }

        return $tree;
    }

    // ================= LLM drivers =================

    private function json(string $class, string $system, string $user): array
    {
        $raw = $this->text($class, $system."\nBalas HANYA JSON valid.", $user, $ti, $to);
        $raw = preg_replace('/^```(json)?|```$/m', '', trim($raw));

        return json_decode(trim($raw), true) ?? [];
    }

    private function text(string $class, string $system, string $user, ?int &$tokensIn = null, ?int &$tokensOut = null, ?callable $onDelta = null): string
    {
        $started = microtime(true);
        $out = $this->driver() === 'openai'
            ? $this->openaiText($class, $system, $user, $tokensIn, $tokensOut, $onDelta)
            : $this->anthropicText($class, $system, $user, $tokensIn, $tokensOut, $onDelta);

        if (config('spekta.llm.log', true)) {
            $ms = (int) ((microtime(true) - $started) * 1000);
            $model = config('spekta.llm.models.'.$class);

            // Payload lengkap → storage/logs/llm-*.log
            \Illuminate\Support\Facades\Log::channel('llm')->debug('llm.call', [
                'driver' => $this->driver(),
                'model' => $model,
                'class' => $class,
                'duration_ms' => $ms,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'system' => $system,
                'user' => $user,
                'response' => $out,
            ]);

            // Ringkasan satu baris → channel default, kelihatan di `php artisan pail`
            \Illuminate\Support\Facades\Log::info(sprintf(
                'LLM %s/%s [%s] %dms · in %d / out %d tokens · %s…',
                $this->driver(), $model, $class, $ms, $tokensIn ?? 0, $tokensOut ?? 0,
                mb_substr(str_replace("\n", ' ', $out), 0, 120)
            ));
        }

        return $out;
    }

    /** Anthropic Messages API — base_url configurable untuk endpoint Anthropic-compatible. */
    private function anthropicText(string $class, string $system, string $user, ?int &$tokensIn = null, ?int &$tokensOut = null, ?callable $onDelta = null): string
    {
        $payload = [
            'model' => config('spekta.llm.models.'.$class),
            'max_tokens' => 8192,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ];
        $pending = Http::withHeaders([
            'x-api-key' => config('spekta.llm.anthropic_key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(180);
        $url = rtrim(config('spekta.llm.base_url'), '/').'/v1/messages';

        if ($onDelta) {
            $acc = '';
            foreach ($this->sse($pending, $url, $payload + ['stream' => true]) as $event) {
                if (($event['type'] ?? '') === 'content_block_delta') {
                    $acc .= $event['delta']['text'] ?? '';
                    $onDelta($acc);
                } elseif (($event['type'] ?? '') === 'message_start') {
                    $tokensIn = $event['message']['usage']['input_tokens'] ?? 0;
                } elseif (($event['type'] ?? '') === 'message_delta') {
                    $tokensOut = $event['usage']['output_tokens'] ?? 0;
                }
            }

            return $acc;
        }

        $body = $pending->retry(2, 2000)->post($url, $payload)->throw()->body();
        // Beberapa proxy Anthropic-compatible menempel sisa SSE ("data: [DONE]") di belakang JSON
        $resp = json_decode(substr($body, 0, strrpos($body, '}') + 1), true)
            ?? throw new \RuntimeException('LLM response bukan JSON valid: '.mb_substr($body, 0, 200));
        $tokensIn = $resp['usage']['input_tokens'] ?? 0;
        $tokensOut = $resp['usage']['output_tokens'] ?? 0;

        return collect($resp['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
    }

    /** OpenAI Chat Completions — kompatibel OpenAI/Groq/DeepSeek/OpenRouter/Ollama dll. */
    private function openaiText(string $class, string $system, string $user, ?int &$tokensIn = null, ?int &$tokensOut = null, ?callable $onDelta = null): string
    {
        $payload = [
            'model' => config('spekta.llm.models.'.$class),
            'max_tokens' => 8192,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
        $pending = Http::withToken(config('spekta.llm.openai_key'))->timeout(180);
        $url = rtrim(config('spekta.llm.openai_base_url'), '/').'/chat/completions';

        if ($onDelta) {
            $acc = '';
            foreach ($this->sse($pending, $url, $payload + ['stream' => true, 'stream_options' => ['include_usage' => true]]) as $event) {
                $acc .= $event['choices'][0]['delta']['content'] ?? '';
                if ($event['choices'][0]['delta']['content'] ?? '') {
                    $onDelta($acc);
                }
                if (isset($event['usage'])) {
                    $tokensIn = $event['usage']['prompt_tokens'] ?? 0;
                    $tokensOut = $event['usage']['completion_tokens'] ?? 0;
                }
            }

            return $acc;
        }

        $resp = $pending->retry(2, 2000)->post($url, $payload)->throw()->json();
        $tokensIn = $resp['usage']['prompt_tokens'] ?? 0;
        $tokensOut = $resp['usage']['completion_tokens'] ?? 0;

        return $resp['choices'][0]['message']['content'] ?? '';
    }

    /** Baca respons SSE → generator array JSON per event `data:`. */
    private function sse(\Illuminate\Http\Client\PendingRequest $pending, string $url, array $payload): \Generator
    {
        $body = $pending->withOptions(['stream' => true])->post($url, $payload)->throw()->toPsrResponse()->getBody();

        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    return;
                }
                $json = json_decode($data, true);
                if (is_array($json)) {
                    yield $json;
                }
            }
        }
    }

    // ================= Stub driver (dev/test) =================

    private function stubUnderstanding(string $input): array
    {
        $sentences = collect(preg_split('/[.\n]+/', $input))->map(fn ($s) => trim($s))->filter(fn ($s) => Str::length($s) > 15)->values();
        $features = $sentences->take(5)->map(fn ($s, $i) => [
            'title' => 'Fitur '.($i + 1).': '.Str::limit(ucfirst($s), 60),
            'quote' => Str::limit($s, 120),
        ])->values()->all();
        if (empty($features)) {
            $features = [['title' => 'Fitur utama aplikasi', 'quote' => Str::limit($input, 120)]];
        }

        return [
            'project_name' => 'Proyek '.Str::limit(ucfirst(trim((string) $sentences->first())), 40, ''),
            'roles' => [['name' => 'Admin', 'note' => 'pengelola sistem'], ['name' => 'User', 'note' => 'pengguna akhir']],
            'features' => $features,
            'domain' => 'Aplikasi bisnis',
            'complexity' => min(5, max(1, (int) ceil(count($features) / 2))),
            'assumptions' => ['Bahasa antarmuka Indonesia', 'Deploy cloud single-region'],
        ];
    }

    private function stubInterview(array $features): array
    {
        $qs = [
            ['question' => 'Berapa perkiraan jumlah pengguna aktif dalam 6 bulan pertama?', 'reason' => 'ditanya karena menentukan kelas arsitektur & biaya infra', 'options' => ['< 1.000', '1.000–10.000', '> 10.000']],
            ['question' => 'Apakah perlu integrasi pembayaran online?', 'reason' => 'ditanya karena mempengaruhi scope, RAB, dan compliance', 'options' => ['Ya, payment gateway lokal', 'Ya, internasional', 'Tidak perlu']],
            ['question' => 'Siapa yang mengelola konten/data master setelah go-live?', 'reason' => 'ditanya karena menentukan kebutuhan panel admin', 'options' => ['Tim internal klien', 'Vendor', 'Belum ditentukan']],
        ];
        foreach (array_slice($features, 0, 2) as $f) {
            $qs[] = [
                'question' => 'Untuk "'.$f['title'].'" — apakah ada aturan bisnis khusus yang harus diikuti?',
                'reason' => 'ditanya karena fitur ini belum punya acceptance criteria',
                'options' => [],
            ];
        }

        return $qs;
    }

    private function stubStructure(array $features): array
    {
        $chunks = array_chunk($features, max(1, (int) ceil(count($features) / 2)));
        $phases = [];
        foreach ($chunks as $i => $chunk) {
            $phases[] = [
                'title' => 'Fase '.($i + 1).($i === 0 ? ' — Fondasi & Core' : ' — Pengembangan'),
                'features' => array_map(fn ($f) => [
                    'title' => $f['title'],
                    'description' => $f['quote'] ?? '',
                    'est_md' => 8,
                    'scope' => $i === 0 ? 'mvp' : 'full',
                    'subfeatures' => [
                        ['title' => 'Desain & skema data', 'est_md' => 2],
                        ['title' => 'Implementasi backend', 'est_md' => 3],
                        ['title' => 'Implementasi frontend', 'est_md' => 3],
                    ],
                ], $chunk),
            ];
        }

        return $phases;
    }

    /** Wireframe stub deterministik: 1 flow per fase, layar list + form per fitur. */
    private function stubWireframes(array $structure): string
    {
        $screens = [];
        foreach ($structure as $phase) {
            $flow = $phase['phase'] ?: 'Flow utama';
            $screens[] = [
                'id' => Str::slug($flow.'-dashboard'),
                'name' => 'Dashboard '.$flow,
                'flow' => $flow,
                'device' => 'desktop',
                'sections' => [
                    ['type' => 'navbar', 'items' => ['Logo', 'Dashboard', 'Pengaturan']],
                    ['type' => 'stats', 'items' => array_map(fn ($f) => $f['title'], array_slice($phase['features'], 0, 3))],
                    ['type' => 'table', 'title' => 'Data terbaru', 'columns' => ['Nama', 'Status', 'Aksi'], 'rows' => 3],
                ],
            ];
            foreach (array_slice($phase['features'], 0, 2) as $f) {
                $screens[] = [
                    'id' => Str::slug($flow.'-'.$f['title']),
                    'name' => $f['title'],
                    'flow' => $flow,
                    'device' => 'desktop',
                    'note' => $f['scope'] === 'mvp' ? 'Scope MVP' : null,
                    'sections' => [
                        ['type' => 'navbar', 'items' => ['Logo', 'Kembali']],
                        ['type' => 'form', 'title' => $f['title'], 'fields' => [
                            ['el' => 'input', 'label' => 'Nama'],
                            ['el' => 'textarea', 'label' => 'Deskripsi'],
                            ['el' => 'button', 'label' => 'Simpan', 'variant' => 'primary'],
                        ]],
                    ],
                ];
            }
        }

        return json_encode(['screens' => $screens], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function stubStack(int $complexity): array
    {
        $arch = $complexity <= 2 ? 'Monolith Laravel' : 'Monolith modular Laravel';

        return [
            ['layer' => 'frontend', 'choice' => 'React + Inertia', 'justification' => 'SPA tanpa API terpisah, cepat dibangun', 'alternatives' => [['choice' => 'Next.js', 'reason_rejected' => 'butuh app terpisah, overhead ops']]],
            ['layer' => 'backend', 'choice' => $arch, 'justification' => 'Sesuai kompleksitas '.$complexity.' (complexity governor BR-16)', 'alternatives' => [['choice' => 'Microservices', 'reason_rejected' => 'di atas batas kelas kompleksitas']]],
            ['layer' => 'database', 'choice' => 'PostgreSQL 16', 'justification' => 'Relasional, JSONB, skala cukup', 'alternatives' => [['choice' => 'MongoDB', 'reason_rejected' => 'data relasional dominan']]],
            ['layer' => 'auth', 'choice' => 'Session + OAuth Google', 'justification' => 'Standar, aman, murah', 'alternatives' => [['choice' => 'Auth0', 'reason_rejected' => 'biaya per MAU tidak perlu']]],
            ['layer' => 'payment', 'choice' => 'Midtrans', 'justification' => 'QRIS/VA/e-wallet pasar Indonesia', 'alternatives' => [['choice' => 'Stripe', 'reason_rejected' => 'penetrasi kartu kredit ID rendah']]],
            ['layer' => 'deploy', 'choice' => 'VPS + Docker Compose', 'justification' => 'Biaya terkendali, cukup untuk skala awal', 'alternatives' => [['choice' => 'Kubernetes', 'reason_rejected' => 'ops berat untuk tim kecil']]],
        ];
    }

    private function stubDocument(Project $project, string $docKey): string
    {
        $u = $project->understanding;
        $structure = $this->structureArray($project);
        $assumptions = $project->assumptions();

        if ($docKey === 'WIREFRAMES') {
            return $this->stubWireframes($structure);
        }

        $md = "# $docKey: {$project->name}\n\n> Digenerate oleh Spekta · ".now()->format('d M Y')." · klien: ".($project->client_name ?? '-')."\n\n";

        if ($docKey === 'PRD') {
            $md .= "## Visi Produk\n\n{$u?->domain} untuk ".($project->client_name ?? 'klien').".\n\n## User Roles\n\n";
            foreach ($u?->roles ?? [] as $r) {
                $md .= "- **{$r['name']}** — {$r['note']}\n";
            }
            $md .= "\n## Functional Requirements\n\n";
            $i = 1;
            foreach ($structure as $phase) {
                foreach ($phase['features'] as $f) {
                    $md .= sprintf("- **FR-%02d %s** (scope: %s, est: %s MD)\n", $i++, $f['title'], $f['scope'], $f['est_md']);
                }
            }
            $md .= "\n## Assumptions\n\n";
            foreach ($assumptions as $a) {
                $md .= "- $a\n";
            }
        } elseif ($docKey === 'REQUIREMENTS') {
            $md .= "## Acceptance Criteria per FR\n\n";
            $i = 1;
            foreach ($structure as $phase) {
                foreach ($phase['features'] as $f) {
                    $n = sprintf('FR-%02d', $i++);
                    $md .= "### $n: {$f['title']}\n\n- Sistem MUST mengimplementasikan {$f['title']} sesuai deskripsi.\n- Fitur MUST teruji dengan skenario positif dan negatif.\n\n";
                }
            }
        } elseif ($docKey === 'ROADMAP') {
            $md .= "## Fase & Prioritas\n\n";
            $i = 1;
            foreach ($structure as $p) {
                $md .= "### {$p['phase']}\n\n";
                foreach ($p['features'] as $f) {
                    $md .= sprintf("- FR-%02d %s (%s)\n", $i++, $f['title'], strtoupper($f['scope']) === 'MVP' ? 'P0' : 'P1');
                }
                $md .= "\n";
            }
        } elseif ($docKey === 'TESTING') {
            $md .= "## Skenario Uji\n\n";
            $i = 1;
            foreach ($structure as $p) {
                foreach ($p['features'] as $f) {
                    $n = sprintf('FR-%02d', $i++);
                    $md .= "- **TS-$n**: verifikasi {$f['title']} — happy path + validasi input.\n";
                }
            }
        } else {
            $md .= "## Ringkasan\n\nDokumen $docKey untuk {$project->name}.\n\n## Detail\n\n";
            foreach ($structure as $p) {
                $md .= "### {$p['phase']}\n\n";
                foreach ($p['features'] as $f) {
                    $md .= "- {$f['title']}\n";
                }
                $md .= "\n";
            }
        }

        return $md;
    }
}
