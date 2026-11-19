<?php

namespace Tests\Support\Work;

use App\Work\WorkPublisher;
use App\Work\WorkWakeUp;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Records wake-ups instead of delivering them; can fail on demand, like an unavailable Cloud Tasks API. */
final class RecordingWorkPublisher implements WorkPublisher
{
    /** @var list<WorkWakeUp> */
    public array $published = [];

    public bool $failing = false;

    /** @var list<int> Transaction levels seen at publish time. */
    public array $levels = [];

    public function publish(WorkWakeUp $wakeUp): void
    {
        $this->levels[] = DB::transactionLevel();
        if ($this->failing) {
            throw new RuntimeException('publish_unavailable');
        }

        $this->published[] = $wakeUp;
    }

    /** @return list<string> */
    public function reasons(): array
    {
        return array_map(fn (WorkWakeUp $wakeUp): string => $wakeUp->reason, $this->published);
    }
}
