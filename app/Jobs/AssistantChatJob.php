<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\SpecEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/** FR-09: jawaban asisten via queue — stream ditulis ke cache, dipoll frontend (pola sama GenerateDocumentJob). */
class AssistantChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public Project $project, public string $question, public ?string $docKey, public ?string $screen = null)
    {
    }

    public function handle(SpecEngine $engine): void
    {
        $key = 'chatstream:'.$this->project->id;
        $last = 0.0;

        try {
            $reply = $engine->chat($this->project, $this->question, $this->docKey, function (string $acc) use ($key, &$last) {
                if (microtime(true) - $last < 0.3) {
                    return; // throttle tulis cache
                }
                $last = microtime(true);
                Cache::put($key, $acc, 300);
            }, $this->screen);
        } catch (\Throwable $e) {
            $reply = 'Maaf, asisten gagal menjawab: '.$e->getMessage();
        }

        $this->project->assistantMessages()->create(['role' => 'assistant', 'body' => $reply]);
        Cache::forget($key);
    }
}
