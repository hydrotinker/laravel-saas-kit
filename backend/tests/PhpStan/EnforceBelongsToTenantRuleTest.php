<?php

namespace Tests\PhpStan;

use App\Models\Attributes\NotTenantScoped;
use App\Models\Concerns\BelongsToTenant;
use App\PhpStan\Rules\EnforceBelongsToTenantRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<EnforceBelongsToTenantRule>
 */
class EnforceBelongsToTenantRuleTest extends RuleTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // RuleTestCase boots a full PHPStan analyser; combined with the rest of
        // the suite in one process this can exceed a low memory_limit. Raise it
        // (never lowering an already-unlimited CI limit).
        if (ini_get('memory_limit') !== '-1') {
            ini_set('memory_limit', '512M');
        }
    }

    protected function getRule(): Rule
    {
        return new EnforceBelongsToTenantRule(
            $this->createReflectionProvider(),
            __DIR__.'/Fixtures/migrations',
        );
    }

    public function test_it_flags_a_tenant_model_missing_the_trait(): void
    {
        $this->analyse([__DIR__.'/Fixtures/Models/UnscopedTenantModel.php'], [
            [
                'Model Tests\PhpStan\Fixtures\Models\UnscopedTenantModel maps to table "widgets", '
                .'which has a tenant_id column, but does not use the '.BelongsToTenant::class.' trait. '
                .'Without it the tenant global scope is never applied and queries leak across tenants. '
                .'Add the trait, or mark the model #['.NotTenantScoped::class.'] if it is intentionally not request-scoped.',
                8,
            ],
        ]);
    }

    public function test_it_derives_the_table_name_from_the_class_when_not_declared(): void
    {
        $this->analyse([__DIR__.'/Fixtures/Models/Sprocket.php'], [
            [
                'Model Tests\PhpStan\Fixtures\Models\Sprocket maps to table "sprockets", '
                .'which has a tenant_id column, but does not use the '.BelongsToTenant::class.' trait. '
                .'Without it the tenant global scope is never applied and queries leak across tenants. '
                .'Add the trait, or mark the model #['.NotTenantScoped::class.'] if it is intentionally not request-scoped.',
                9,
            ],
        ]);
    }

    public function test_the_opt_out_attribute_exposes_its_reason(): void
    {
        $this->assertSame('looked up before tenant context', (new NotTenantScoped('looked up before tenant context'))->reason);
    }

    public function test_it_allows_a_tenant_model_using_the_trait(): void
    {
        $this->analyse([__DIR__.'/Fixtures/Models/ScopedTenantModel.php'], []);
    }

    public function test_it_allows_a_tenant_model_explicitly_opted_out(): void
    {
        $this->analyse([__DIR__.'/Fixtures/Models/ExemptTenantModel.php'], []);
    }

    public function test_it_ignores_a_model_on_a_table_without_tenant_id(): void
    {
        $this->analyse([__DIR__.'/Fixtures/Models/NonTenantModel.php'], []);
    }

    public function test_it_ignores_pivot_models(): void
    {
        $this->analyse([__DIR__.'/Fixtures/Models/TenantPivotModel.php'], []);
    }
}
