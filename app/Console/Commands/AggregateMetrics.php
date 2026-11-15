<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Jobs\AggregateTenantDays;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds agg_daily and agg_source_daily again for the given days (docs/spec.md 9, T21). One job per tenant, so
 * every tenant runs under its own context and row-level security. Idempotent.
 */
class AggregateMetrics extends Command
{
    protected $signature = 'ekko:aggregate
        {date? : A day (Y-m-d, in each tenant\'s timezone). Default: yesterday and today}
        {--from= : First day of a range (Y-m-d), with --to}
        {--to= : Last day of a range (Y-m-d). Default: the day of --from}
        {--tenant=* : Only these tenant ids}
        {--sync : Run in this process instead of on the queue}';

    protected $description = 'Rebuild the daily aggregates (agg_daily, agg_source_daily) and model change markers for a day or a range';

    public function handle(): int
    {
        try {
            $days = $this->days();
        } catch (Throwable) {
            $this->components->error(__('metrics.aggregate.bad_date'));

            return self::FAILURE;
        }

        $tenants = Team::query()
            ->where('personal_team', false)
            ->where('status', '!=', TenantStatus::Closed->value)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', array_map('intval', (array) $ids)))
            ->orderBy('id')
            ->get(['id', 'timezone']);

        foreach ($tenants as $tenant) {
            // Without a date the days are today and yesterday in the tenant's own timezone, so late results
            // for yesterday are included.
            $dates = $days ?? $this->defaultDays((string) $tenant->timezone);
            $job = new AggregateTenantDays($tenant->id, $dates);
            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
        }

        $this->components->info(__($this->option('sync') ? 'metrics.aggregate.done' : 'metrics.aggregate.queued', ['tenants' => $tenants->count()]));

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null null when no day was given
     */
    private function days(): ?array
    {
        $from = $this->option('from') ?: $this->argument('date');
        if ($from === null) {
            return null;
        }

        $first = $this->parse((string) $from);
        $last = $this->option('to') ? $this->parse((string) $this->option('to')) : $first;
        if ($last->lt($first) || $first->diffInDays($last) > 400) {
            throw new \InvalidArgumentException('Bad range');
        }

        $days = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    private function parse(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Bad date');
        }

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private function defaultDays(string $timezone): array
    {
        try {
            $today = CarbonImmutable::now($timezone);
        } catch (Throwable) {
            $today = CarbonImmutable::now('UTC');
        }

        return [$today->subDay()->format('Y-m-d'), $today->format('Y-m-d')];
    }
}
