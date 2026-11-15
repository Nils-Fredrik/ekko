<?php

namespace App\Http\Controllers;

use App\BotLogs\BrandLogDomains;
use App\Jobs\ImportBotLog;
use App\Models\BotLogUpload;
use App\Models\Brand;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class BotLogUploadController extends Controller
{
    public function index(Team $team): Response
    {
        abort_unless($team->id === current_tenant_id(), 404);
        Gate::authorize('viewAny', BotLogUpload::class);

        $brands = Brand::query()
            ->whereDoesntHave('integrations', fn ($query) => $query->where('type', 'clickhouse_logs'))
            ->with('domains')
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'domains' => array_keys(BrandLogDomains::for($brand)),
            ])
            ->filter(fn (array $brand) => $brand['domains'] !== [])
            ->values();

        return Inertia::render('BotLogs/Index', [
            'brands' => $brands,
            'enabled' => $team->isActive(),
            'uploads' => BotLogUpload::query()->latest('id')->limit(10)->get(['id', 'domain', 'status']),
        ]);
    }

    public function store(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('create', BotLogUpload::class);
        abort_unless(Team::query()->find(current_tenant_id())?->isActive(), 403);

        if ($brand->integrations()->where('type', 'clickhouse_logs')->exists()) {
            throw ValidationException::withMessages(['log' => __('bot_logs.clickhouse_enabled')]);
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', Rule::in(array_keys(BrandLogDomains::for($brand)))],
            'log' => ['required', 'file', 'max:'.config()->integer('ekko.bot_logs.max_upload_kib')],
        ]);

        $file = $request->file('log');
        $bytes = $file instanceof UploadedFile ? $file->get() : false;
        if ($bytes === false || ! str_starts_with($bytes, "\x1f\x8b")) {
            throw ValidationException::withMessages(['log' => __('bot_logs.invalid_gzip')]);
        }

        $upload = BotLogUpload::query()->create([
            'brand_id' => $brand->id,
            'domain' => $validated['domain'],
            'payload' => base64_encode($bytes),
            'status' => 'pending',
        ]);

        ImportBotLog::dispatch(current_tenant_id(), $upload->id);

        return to_route('bot-log-uploads.index', ['team' => current_tenant_id()]);
    }
}
