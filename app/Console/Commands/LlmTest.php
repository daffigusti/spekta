<?php

namespace App\Console\Commands;

use App\Services\SpecEngine;
use Illuminate\Console\Command;

class LlmTest extends Command
{
    protected $signature = 'spekta:llm-test {prompt? : Prompt uji (default: minta satu warna)} {--class=economy : Node-class model (reasoning|standard|economy)} {--stream : Uji jalur streaming SSE}';

    protected $description = 'Smoke-test koneksi LLM: kirim prompt pendek, tampilkan driver/model/URL/latensi/respons';

    public function handle(SpecEngine $engine): int
    {
        $class = (string) $this->option('class');
        $prompt = (string) ($this->argument('prompt') ?? 'Sebutkan satu warna, jawab satu kata saja.');

        $this->line('driver   : '.$engine->driver());
        $this->line('base_url : '.config('spekta.llm.base_url'));
        $this->line('model    : '.config('spekta.llm.models.'.$class)." ({$class})");

        // ponytail: pakai method private via reflection — jalur produksi persis, tanpa API publik baru
        $method = (new \ReflectionClass($engine))->getMethod('text');
        $start = microtime(true);

        try {
            $onDelta = $this->option('stream') ? fn (string $acc) => null : null;
            $out = $method->invokeArgs($engine, [$class, 'Kamu asisten uji koneksi.', $prompt, &$in, &$tokensOut, $onDelta]);
        } catch (\Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $ms = (int) ((microtime(true) - $start) * 1000);
        $this->line('latency  : '.$ms.'ms · tokens in '.($in ?? 0).' / out '.($tokensOut ?? 0));
        $this->info('response : '.trim($out));

        return trim($out) === '' ? self::FAILURE : self::SUCCESS;
    }
}
