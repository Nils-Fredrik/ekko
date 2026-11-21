<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Prompts\PersonalData;
use App\Prompts\PromptBulk;
use App\Prompts\PromptCsv;
use App\Prompts\PromptLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class PromptLibraryController extends Controller
{
    public function store(Request $request, Brand $brand, Market $market, PromptLibrary $library): RedirectResponse
    {
        Gate::authorize('create', Prompt::class);
        abort_unless($market->brand_id === $brand->id, 404);
        $prompt = $library->create($market, $request->validate(PromptLibrary::rules(true)));

        return back()->with(['prompt_id' => $prompt->id, 'personal_data' => PersonalData::in($prompt->text)]);
    }

    public function destroy(Brand $brand, Prompt $prompt, PromptLibrary $library): RedirectResponse
    {
        Gate::authorize('delete', $prompt);
        abort_unless($prompt->market?->brand_id === $brand->id, 404);
        $library->delete($prompt);

        return back();
    }

    public function bulk(Request $request, Brand $brand, PromptLibrary $library): JsonResponse
    {
        Gate::authorize('create', Prompt::class);
        $action = $request->validate(['action' => ['required', Rule::in(PromptBulk::ACTIONS)]])['action'];
        if ($action === 'create') {
            $rules = [
                'market_id' => ['required', 'integer', 'min:1'],
                'prompts' => ['required', 'array', 'list', 'min:1', 'max:25'],
                'prompts.*' => ['required', 'array:text,topic_id,intent,funnel_stage,priority,is_active'],
            ];
            foreach (PromptLibrary::rules(true) as $field => $fieldRules) {
                $rules['prompts.*.'.$field] = $fieldRules;
            }
            $data = $request->validate($rules);
            $result = $library->bulk($brand, [], $action, $data);

            return response()->json([
                'affected' => $result->affected,
                'undo' => $result->undo,
                'personal_data' => array_any($data['prompts'], fn (array $row): bool => PersonalData::in($row['text'])),
            ]);
        }
        $data = $request->validate([
            'ids' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'value' => match ($action) {
                'undo' => ['required', 'string'],
                'move_topic' => ['present', 'nullable', 'integer', 'min:1'],
                default => ['nullable'],
            },
        ]);

        return response()->json($library->bulk($brand, array_map('intval', $data['ids']), $action, $data['value'] ?? null));
    }

    public function preview(Request $request, Brand $brand): JsonResponse
    {
        Gate::authorize('create', Prompt::class);

        return response()->json(PromptCsv::preview($brand, self::csv($request)));
    }

    public static function csv(Request $request): string
    {
        $data = $request->validate([
            'file' => ['required_without:csv', 'file', 'max:2048'], 'csv' => ['required_without:file', 'string', 'max:500000'],
        ]);

        return $data['csv'] ?? (string) $request->file('file')?->get();
    }
}
