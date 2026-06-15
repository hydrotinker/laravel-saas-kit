<?php

namespace Tests\PhpStan\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

// On a table without a tenant_id column (gadgets) -> no error, trait not required.
class NonTenantModel extends Model
{
    protected $table = 'gadgets';
}
