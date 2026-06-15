<?php

namespace App\Models\Attributes;

use App\Models\Concerns\BelongsToTenant;
use App\PhpStan\Rules\EnforceBelongsToTenantRule;
use Attribute;

/**
 * Marks an Eloquent model as a deliberate exception to tenant scoping.
 *
 * A model whose table carries a `tenant_id` column is normally required to use
 * the {@see BelongsToTenant} trait — the
 * {@see EnforceBelongsToTenantRule} static-analysis rule fails
 * the build otherwise. Some tables legitimately carry `tenant_id` without being
 * scoped to the current request's tenant (e.g. refresh tokens, which are looked
 * up before any tenant context exists). Annotate those models with this attribute
 * and a `$reason` so the exemption is explicit and auditable rather than silent.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NotTenantScoped
{
    public function __construct(public string $reason = '') {}
}
