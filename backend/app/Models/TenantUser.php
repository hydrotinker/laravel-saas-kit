<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot for the `tenant_user` membership table.
 *
 * @property string $role
 */
class TenantUser extends Pivot {}
