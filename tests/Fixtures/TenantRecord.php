<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenantSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant-owned model for testing BelongsToTenant before the real tables exist (T08).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 */
class TenantRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'test_tenant_records';

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'tenant_id'];

    public static function createTable(): void
    {
        Schema::create('test_tenant_records', function (Blueprint $table) {
            $table->id();
            TenantSchema::tenantColumn($table);
            $table->string('name');
            $table->timestamps();
        });
    }
}
