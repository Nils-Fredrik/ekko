<?php

namespace App\Observers;

use App\Models\Plan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PlanObserver
{
    public function created(Plan $plan): void
    {
        $this->record('created', $plan);
    }

    public function updated(Plan $plan): void
    {
        $this->record('updated', $plan);
    }

    public function deleted(Plan $plan): void
    {
        $this->record('deleted', $plan);
    }

    private function record(string $event, Plan $plan): void
    {
        $context = [
            'plan_id' => $plan->id,
            'user_id' => Auth::id(),
            'fields' => $event === 'updated' ? array_keys($plan->getChanges()) : [],
        ];

        DB::afterCommit(fn () => Log::info("plan.{$event}", $context));
    }
}
