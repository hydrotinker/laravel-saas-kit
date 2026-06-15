<?php

namespace Tests\PhpStan\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

// On a tenant table (widgets) but missing BelongsToTenant -> the rule must flag it.
class UnscopedTenantModel extends Model
{
    protected $table = 'widgets';
}
