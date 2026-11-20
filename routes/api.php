<?php

use App\Http\Controllers\Api\ActionController;
use App\Http\Controllers\Api\FactCheckController;
use App\Http\Controllers\Api\PitchController;
use App\Http\Controllers\Api\PromptController;
use App\Http\Controllers\Api\ReadController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Middleware\ApiJsonResponse;
use App\Http\Middleware\ApiTokenContext;
use App\Http\Middleware\ApplyTenantRls;
use App\Http\Middleware\RequireApiAbility;
use App\Http\Middleware\SetCurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'two-factor.required']);

// A browser session cannot substitute for a tenant-bound personal access token.
Route::prefix('v1')->name('api.v1.')
    ->withoutMiddleware([SetCurrentTenant::class, ApplyTenantRls::class])
    ->middleware([ApiJsonResponse::class, 'auth:sanctum', 'verified', 'two-factor.required', 'throttle:api-v1', ApiTokenContext::class])
    ->group(function (): void {
        Route::middleware(RequireApiAbility::class.':read')->group(function (): void {
            Route::get('brands', [ReadController::class, 'brands'])->name('brands.index');
            foreach (['metrics', 'prompts', 'responses', 'sources', 'competitors'] as $resource) {
                Route::get('brands/{brand}/'.$resource, [ReadController::class, $resource])->name($resource.'.index');
            }
            Route::get('brands/{brand}/actions', [ActionController::class, 'index'])->name('actions.index');
            Route::get('actions/{action}', [ActionController::class, 'show'])->name('actions.show');
            Route::get('brands/{brand}/facts', [FactCheckController::class, 'facts'])->name('facts.index');
            Route::get('brands/{brand}/claims', [FactCheckController::class, 'claims'])->name('claims.index');
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
            Route::get('alerts', [ReadController::class, 'alerts'])->name('alerts.index');
            Route::get('usage', [ReadController::class, 'usage'])->name('usage.index');
        });
        Route::middleware(RequireApiAbility::class.':write')->group(function (): void {
            Route::post('brands/{brand}/actions', [ActionController::class, 'store'])->name('actions.store');
            Route::patch('brands/{brand}/actions', [ActionController::class, 'update'])->name('actions.update');
            Route::post('brands/{brand}/facts', [FactCheckController::class, 'store'])->name('facts.store');
            Route::patch('brands/{brand}/facts', [FactCheckController::class, 'update'])->name('facts.update');
            Route::delete('brands/{brand}/facts', [FactCheckController::class, 'destroy'])->name('facts.destroy');
            Route::post('reports/{report}/links', [ReportController::class, 'storeLink'])->name('report-links.store');
            Route::delete('reports/{report}/links', [ReportController::class, 'destroyLink'])->name('report-links.destroy');
            Route::get('pitches', [PitchController::class, 'index'])->name('pitches.index');
            Route::post('pitches', [PitchController::class, 'store'])->name('pitches.store');
            Route::post('brands/{brand}/prompts', [PromptController::class, 'store'])->name('prompts.store');
            Route::patch('brands/{brand}/prompts', [PromptController::class, 'update'])->name('prompts.update');
            Route::delete('brands/{brand}/prompts', [PromptController::class, 'destroy'])->name('prompts.destroy');
        });
    });
