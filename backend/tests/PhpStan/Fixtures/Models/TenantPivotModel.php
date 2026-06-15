<?php

namespace Tests\PhpStan\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

// A pivot on a tenant table -> excluded from the rule (join tables are not scoped entities).
class TenantPivotModel extends Pivot
{
    protected $table = 'widgets';
}
