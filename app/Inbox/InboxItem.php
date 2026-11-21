<?php

namespace App\Inbox;

use App\Models\Brand;
use App\Models\Market;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use JsonSerializable;

/** A row can represent several source records. Source IDs are also the payload for bulk operations. */
final readonly class InboxItem implements JsonSerializable
{
    /**
     * @param  list<string>  $buttons
     * @param  array<string, mixed>  $context  Action-specific fields for the inbox controls.
     * @param  list<int|string>  $sourceIds
     */
    public function __construct(
        public string $type, public string $key, public ?Brand $brand, public ?Market $market,
        public DateTimeInterface|string $at, public string $edge, public string $title, public string $detail,
        public array $buttons, public string $url, public bool $handled, public array $sourceIds, public array $context = [],
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->type.':'.$this->key, 'type' => $this->type,
            'brand' => $this->brand ? ['id' => $this->brand->id, 'name' => $this->brand->name] : null,
            'market' => $this->market ? ['id' => $this->market->id, 'label' => $this->market->location_label ?: strtoupper($this->market->country)] : null,
            'at' => CarbonImmutable::parse($this->at)->utc()->toIso8601String(), 'edge' => $this->edge,
            'title' => $this->title, 'detail' => $this->detail, 'handled' => $this->handled,
            'actions' => array_map(fn (string $key) => ['key' => $key, 'label' => __('inbox.buttons.'.$key), 'primary' => $key === $this->buttons[0]], $this->buttons),
            'target_url' => $this->url, 'source_ids' => $this->sourceIds, ...$this->context,
        ];
    }
}
