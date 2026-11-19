<?php

namespace App\Http\Controllers;

use App\ActionEngine\ActionTemplates;
use App\ActionEngine\ActionWorkflow;
use App\ActionEngine\InvalidActionTransition;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Topic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** The action list, detail and the user's own actions (docs/spec.md 11.3, T44). */
class BrandActionController extends Controller
{
    public function __construct(private readonly ActionWorkflow $workflow) {}

    public function index(Request $request, Brand $brand): Response
    {
        Gate::authorize('viewAny', Action::class);
        foreach (['status', 'type', 'topic'] as $key) {
            if ($request->input($key) === '') {
                $request->merge([$key => null]);
            }
        }
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ActionStatus::class)],
            'type' => ['nullable', Rule::in([...array_column(ActionType::cases(), 'value'), 'own'])],
            'topic' => ['nullable', 'integer'],
        ]);

        $actions = Action::query()
            ->where('brand_id', $brand->id)
            ->with(['topic:id,name', 'market:id,country,language'])
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['type'] ?? null) === 'own', fn ($query) => $query->whereNull('type'))
            ->when(isset($filters['type']) && $filters['type'] !== 'own', fn ($query) => $query->where('type', $filters['type']))
            ->when(($filters['topic'] ?? null) !== null, fn ($query) => $query->where('topic_id', $filters['topic']))
            ->ranked()
            ->get();

        return Inertia::render('Brands/Actions', [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'filters' => [
                'status' => $filters['status'] ?? '',
                'type' => $filters['type'] ?? '',
                'topic' => $filters['topic'] ?? '',
            ],
            'actions' => $actions->map(fn (Action $action): array => $this->summary($action))->values(),
            'topics' => $this->topics($brand),
            'markets' => $this->markets($brand),
            'prompts' => $this->prompts($brand),
            'statuses' => array_column(ActionStatus::cases(), 'value'),
            'types' => [...array_column(ActionType::cases(), 'value'), 'own'],
            'canManage' => Gate::allows('create', Action::class),
        ]);
    }

    public function show(Brand $brand, Action $action): Response
    {
        Gate::authorize('view', $action);
        $this->belongsToBrand($brand, $action);
        $action->load(['topic:id,name', 'market:id,country,language', 'prompts:id,text']);

        return Inertia::render('Brands/ActionShow', [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'action' => [
                ...$this->summary($action),
                'description' => $action->description,
                'evidence' => ActionTemplates::evidenceSentence($action, app()->getLocale()) ?? '',
                'figures' => $this->figures($action->evidence),
                'competitors' => $this->competitors($action),
                'prompts' => $action->prompts->map(fn (Prompt $prompt): array => [
                    'id' => $prompt->id,
                    'text' => $prompt->text,
                ])->values(),
                'engines' => $action->engine_keys,
                'steps' => $action->steps,
            ],
            'canManage' => Gate::allows('update', $action),
        ]);
    }

    public function store(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('create', Action::class);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'steps' => ['nullable', 'string', 'max:5000'],
            'type' => ['nullable', Rule::enum(ActionType::class)],
            'market_id' => ['nullable', 'integer'],
            'topic_id' => ['nullable', 'integer'],
            'prompt_ids' => ['array'],
            'prompt_ids.*' => ['integer'],
        ]);
        $steps = array_values(array_filter(array_map(
            fn (string $step): string => trim($step),
            preg_split('/\R/', (string) ($data['steps'] ?? '')) ?: [],
        ), fn (string $step): bool => $step !== ''));

        try {
            $action = $this->workflow->createOwn(
                $brand,
                $data['title'],
                (string) ($data['description'] ?? ''),
                $steps,
                isset($data['type']) ? ActionType::from($data['type']) : null,
                $data['market_id'] ?? null,
                $data['topic_id'] ?? null,
                $data['prompt_ids'] ?? [],
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['title' => __('action_items.invalid_input')]);
        }

        return redirect()->route('brands.actions.show', [$brand, $action]);
    }

    public function update(Request $request, Brand $brand, Action $action): RedirectResponse
    {
        Gate::authorize('update', $action);
        $this->belongsToBrand($brand, $action);
        $status = ActionStatus::from($request->validate([
            'status' => ['required', Rule::enum(ActionStatus::class)],
        ])['status']);

        try {
            $this->workflow->transition($action, $status);
        } catch (InvalidActionTransition) {
            throw ValidationException::withMessages(['status' => __('action_items.invalid_status')]);
        }

        return back();
    }

    public function step(Request $request, Brand $brand, Action $action, int $step): RedirectResponse
    {
        Gate::authorize('update', $action);
        $this->belongsToBrand($brand, $action);
        $done = $request->validate(['done' => ['required', 'boolean']])['done'];

        try {
            $this->workflow->setStepDone($action, $step, (bool) $done);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['done' => __('action_items.invalid_input')]);
        }

        return back();
    }

    private function belongsToBrand(Brand $brand, Action $action): void
    {
        abort_unless($action->brand_id === $brand->id, 404);
    }

    /** @return array<string, mixed> */
    private function summary(Action $action): array
    {
        return [
            'id' => $action->id,
            'title' => $action->title,
            'status' => $action->status->value,
            'type' => $action->type instanceof ActionType ? $action->type->value : 'own',
            'topic' => $action->topic?->name,
            'market' => $action->market === null ? null : $action->market->country.' · '.$action->market->language,
            'impact' => $action->impact_level,
            'targets' => array_map(
                fn (ActionStatus $status): string => $status->value,
                $action->status->userTargets(),
            ),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function topics(Brand $brand): array
    {
        return Topic::query()->where('brand_id', $brand->id)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Topic $topic): array => ['id' => $topic->id, 'name' => $topic->name])->all();
    }

    /** @return list<array{id: int, label: string}> */
    private function markets(Brand $brand): array
    {
        return Market::query()->where('brand_id', $brand->id)->orderBy('id')->get(['id', 'country', 'language'])
            ->map(fn (Market $market): array => [
                'id' => $market->id,
                'label' => $market->country.' · '.$market->language,
            ])->all();
    }

    /** @return list<array{id: int, text: string}> */
    private function prompts(Brand $brand): array
    {
        return Prompt::query()->whereHas('market', fn ($query) => $query->where('brand_id', $brand->id))
            ->orderBy('id')->get(['id', 'text'])
            ->map(fn (Prompt $prompt): array => ['id' => $prompt->id, 'text' => $prompt->text])->all();
    }

    /**
     * Figures from the stored evidence, labelled with the keys the sources write. Rates and the period are
     * formatted; text_params stay in the sentence and are not repeated here.
     *
     * @param  array<string, mixed>  $evidence
     * @return list<array{label: string, value: string}>
     */
    private function figures(array $evidence): array
    {
        $rows = [];
        foreach ($evidence as $key => $value) {
            if ($key === 'text_params') {
                continue;
            }
            $label = __("action_items.fields.{$key}");
            if ($label === "action_items.fields.{$key}") {
                continue;
            }
            $formatted = $this->figure($key, $value);
            if ($formatted === null) {
                continue;
            }
            $rows[] = ['label' => $label, 'value' => $formatted];
        }

        return $rows;
    }

    private function figure(string $key, mixed $value): ?string
    {
        if ($key === 'period' && is_array($value)) {
            $from = $value['from'] ?? null;
            $to = $value['to'] ?? null;
            if (! is_string($from) || $from === '' || ! is_string($to) || $to === '') {
                return null;
            }

            return __('action_items.period_value', ['from' => $from, 'to' => $to]);
        }
        if (in_array($key, ['visibility', 'share'], true) && is_array($value)) {
            return $this->percent(is_numeric($value['value'] ?? null) ? (float) $value['value'] : null);
        }
        if ($key === 'max_visibility' && is_numeric($value)) {
            return $this->percent((float) $value);
        }
        if ($key === 'threshold_percent' && is_numeric($value)) {
            return $this->percent((float) $value, true);
        }
        if (is_array($value) || $value === null) {
            return null;
        }

        return (string) $value;
    }

    private function percent(?float $value, bool $alreadyPercent = false): string
    {
        if ($value === null) {
            return __('reports.empty_value');
        }
        $decimal = app()->getLocale() === 'en' ? '.' : ',';

        return number_format($alreadyPercent ? $value : $value * 100, 1, $decimal, ' ').' %';
    }

    /** @return list<string> */
    private function competitors(Action $action): array
    {
        $lines = [];
        foreach ($action->competitor_evidence as $row) {
            if (! isset($row['name'])) {
                continue;
            }
            $lines[] = __('action_items.competitor_line', [
                'name' => (string) $row['name'],
                'answers' => (string) ($row['answers'] ?? 0),
                'of' => (string) ($row['of'] ?? 0),
            ]);
        }

        return $lines;
    }
}
