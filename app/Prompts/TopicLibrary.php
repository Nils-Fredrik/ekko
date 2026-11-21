<?php

namespace App\Prompts;

use App\Models\Brand;
use App\Models\Prompt;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TopicLibrary
{
    public function create(Brand $brand, string $name): Topic
    {
        return DB::transaction(function () use ($brand, $name): Topic {
            Brand::query()->lockForUpdate()->findOrFail($brand->id);
            $this->checkName($brand->id, $name);
            // Market confirmation accepts keys up to 100 characters, including any collision suffix.
            $base = substr(Str::slug($name) ?: 'topic', 0, 100);
            $key = $base;
            for ($suffix = 2; $brand->topics()->where('cross_market_key', $key)->exists(); $suffix++) {
                $ending = '-'.$suffix;
                $key = rtrim(substr($base, 0, 100 - strlen($ending)), '-').$ending;
            }

            return $brand->topics()->create(['name' => $name, 'cross_market_key' => $key]);
        });
    }

    public function rename(Topic $topic, string $name): void
    {
        DB::transaction(function () use ($topic, $name): void {
            Brand::query()->lockForUpdate()->findOrFail($topic->brand_id);
            $locked = Topic::query()->lockForUpdate()->findOrFail($topic->id);
            $this->checkName($locked->brand_id, $name, $locked->id);
            $locked->update(['name' => $name]);
        });
    }

    public function merge(Topic $from, Topic $into): void
    {
        abort_unless($from->brand_id === $into->brand_id, 404);
        if ($from->is($into)) {
            throw ValidationException::withMessages(['into' => __('app.library.same_topic')]);
        }
        DB::transaction(function () use ($from, $into): void {
            $brand = Brand::query()->lockForUpdate()->findOrFail($from->brand_id);
            // Acquire market locks before updating prompts, as all prompt writers do.
            $brand->markets()->orderBy('id')->lockForUpdate()->get(['id']);
            $topics = $brand->topics()->whereKey([$from->id, $into->id])->orderBy('id')->lockForUpdate()->get();
            abort_unless($topics->count() === 2, 404);
            $from->prompts()->eachById(fn (Prompt $prompt) => $prompt->update(['topic_id' => $into->id]));
            $from->delete();
        });
    }

    public function delete(Topic $topic): void
    {
        DB::transaction(function () use ($topic): void {
            $locked = Topic::query()->lockForUpdate()->findOrFail($topic->id);
            if ($locked->prompts()->exists()) {
                throw TopicNotEmpty::withMessages(['topic_not_empty' => __('app.library.topic_not_empty')]);
            }
            $locked->delete();
        });
    }

    private function checkName(int $brandId, string $name, ?int $except = null): void
    {
        if (Topic::query()->where('brand_id', $brandId)->whereRaw('lower(name) = lower(?)', [$name])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['name' => __('app.library.duplicate')]);
        }
    }
}
