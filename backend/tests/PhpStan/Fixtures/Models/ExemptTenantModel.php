<?php

namespace Tests\PhpStan\Fixtures\Models;

use App\Models\Attributes\NotTenantScoped;
use Illuminate\Database\Eloquent\Model;

// On a tenant table (widgets), no trait, but explicitly opted out -> no error.
#[NotTenantScoped('fixture: intentionally unscoped')]
class ExemptTenantModel extends Model
{
    protected $table = 'widgets';
}
