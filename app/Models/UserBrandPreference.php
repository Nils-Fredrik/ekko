<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class UserBrandPreference extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['user_id', 'brand_id', 'checklist_hidden_at'];
}
