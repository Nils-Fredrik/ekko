<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetTenantContext;
use App\Mail\ReportMail;
use App\Models\ReportSchedule;
use App\Models\Team;
use App\Models\User;
use App\Reports\DueReport;
use App\Reports\ReportPublisher;
use App\Reports\ReportWindow;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SendTestReport extends TenantJob
{
    public function __construct(int $tenantId, public readonly int $scheduleId, public readonly int $userId)
    {
        parent::__construct($tenantId);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(TenantRls $rls, ReportPublisher $publisher): void
    {
        $preview = $rls->run([$this->tenantId], function () use ($publisher) {
            $tenant = Team::findOrFail($this->tenantId);
            $schedule = ReportSchedule::with('brand')->find($this->scheduleId);
            $user = User::find($this->userId);
            if (! $schedule || ! $user || ! Gate::forUser($user)->allows('update', $schedule)) {
                return null;
            }
            [$from, $to] = ReportWindow::previous($schedule->cadence, new DateTimeZone($tenant->timezone), CarbonImmutable::now());
            $due = new DueReport($schedule->id, $schedule->brand_id, $schedule->cadence, $schedule->locale, [$user->email], $from, $to, []);

            return [$due, $schedule->brand->name, $publisher->document($tenant, $due, null)['data']];
        });
        if ($preview === null) {
            return;
        }
        [$due, $name, $data] = $preview;
        $path = 'reports/'.$this->tenantId.'/preview-'.Str::uuid().'.pdf';
        $disk = Storage::disk(config()->string('ekko.reports.disk'));
        try {
            $publisher->write($path, $data, $due->locale);
            $pdf = $disk->get($path);
            if (! is_string($pdf) || $pdf === '') {
                throw new \RuntimeException('Report preview PDF was not written.');
            }
            Mail::to($due->recipients[0])->locale($due->locale)->send(new ReportMail($name, $due->from, $due->to, $pdf));
        } finally {
            $disk->delete($path);
        }
    }
}
