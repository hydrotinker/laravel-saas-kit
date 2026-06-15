<?php

namespace Tests\PhpStan\Fixtures\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

// On a tenant table (widgets) and correctly uses the trait -> no error.
class ScopedTenantModel extends Model
{
    use BelongsToTenant;

    protected $table = 'widgets';
}
