<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Prompt;
use App\Models\Topic;
use App\Prompts\PromptSuggester;
use App\Prompts\TopicLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class BrandTopicController extends Controller
{
    public function store(Request $request, Brand $brand, TopicLibrary $library): RedirectResponse
    {
        Gate::authorize('create', Topic::class);
        $topic = $library->create($brand, $this->name($request));

        return back()->with('topic_id', $topic->id);
    }

    public function update(Request $request, Brand $brand, Topic $topic, TopicLibrary $library): RedirectResponse
    {
        $this->manage($brand, $topic);
        $library->rename($topic, $this->name($request));

        return back();
    }

    public function merge(Request $request, Brand $brand, Topic $topic, TopicLibrary $library): RedirectResponse
    {
        $this->manage($brand, $topic);
        $id = $request->validate(['into' => ['required', 'integer', 'min:1']])['into'];
        $library->merge($topic, $brand->topics()->findOrFail($id));

        return back()->with('topic_id', (int) $id);
    }

    public function destroy(Brand $brand, Topic $topic, TopicLibrary $library): RedirectResponse
    {
        $this->manage($brand, $topic);
        $library->delete($topic);

        return back();
    }

    public function suggestions(Request $request, Brand $brand, Topic $topic, PromptSuggester $suggester): JsonResponse
    {
        $this->manage($brand, $topic);
        $data = $request->validate(['market_id' => ['required', 'integer', 'min:1'], 'count' => ['required', 'integer', Rule::in([5, 10])]]);
        $market = $brand->markets()->findOrFail($data['market_id']);

        return response()->json(['suggestions' => $suggester->forTopic($topic, $market, (int) $data['count'])]);
    }

    private function name(Request $request): string
    {
        return $request->validate(['name' => ['required', 'string', 'max:100', Prompt::nullByteRule()]])['name'];
    }

    private function manage(Brand $brand, Topic $topic): void
    {
        abort_unless($topic->brand_id === $brand->id, 404);
        Gate::authorize('update', $topic);
    }
}
