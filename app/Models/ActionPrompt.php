<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A prompt an action concerns (action_prompts, docs/spec.md 11.3). Written in bulk through the query builder by
 * ActionGenerator and ActionWorkflow; read through Action::prompts().
 *
 * @property int $action_id
 * @property int $prompt_id
 */
class ActionPrompt extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'action_prompts';

    protected $primaryKey = 'action_id';

    /** @var list<string> */
    protected $fillable = ['action_id', 'prompt_id'];
}
