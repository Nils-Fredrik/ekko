<?php

namespace Tests\Support\Work;

use App\Work\WorkClaim;
use App\Work\WorkHandler;
use App\Work\WorkOutcome;
use Closure;

/** A work handler whose behaviour the test sets; records the claims it got. */
final class CallbackWorkHandler implements WorkHandler
{
    /** @var list<WorkClaim> */
    public array $claims = [];

    /** @param  Closure(WorkClaim): WorkOutcome  $callback */
    public function __construct(private Closure $callback) {}

    public function handle(WorkClaim $claim): WorkOutcome
    {
        $this->claims[] = $claim;

        return ($this->callback)($claim);
    }
}
