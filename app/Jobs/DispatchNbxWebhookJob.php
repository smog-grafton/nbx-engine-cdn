<?php

namespace App\Jobs;

use App\Models\MediaSource;
use App\Services\NbxWebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchNbxWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public int $sourceId,
        public string $event,
        public array $context = [],
    ) {
        $this->onQueue((string) config('nbx.webhook_queue', 'nbx-webhook'));
    }

    public function handle(NbxWebhookDispatcher $dispatcher): void
    {
        $source = MediaSource::with('asset')->find($this->sourceId);
        if (! $source) {
            return;
        }

        $dispatcher->send($source, $this->event, $this->context);
    }
}
