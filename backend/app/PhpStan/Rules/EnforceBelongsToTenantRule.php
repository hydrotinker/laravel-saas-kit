<?php

namespace App\PhpStan\Rules;

use App\Models\Attributes\NotTenantScoped;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Fails static analysis when an Eloquent model whose table carries a `tenant_id`
 * column does not use the {@see BelongsToTenant} trait.
 *
 * Row-level multi-tenancy (ARCHITECTURE.md §2.1–2.2) relies on a global scope
 * applied by that trait. Forgetting it on a tenant-owned table produces a silent
 * cross-tenant data leak that no type error catches. This rule moves the
 * guarantee from "a feature test happens to exercise it" to "CI mechanically
 * refuses to merge an unscoped tenant table".
 *
 * Tenant tables are discovered by parsing the migration files on disk (so the
 * rule needs no database connection — the CI static-analysis job has none).
 * A model that legitimately carries `tenant_id` without being request-scoped
 * (e.g. RefreshToken) opts out with the {@see NotTenantScoped} attribute.
 *
 * @implements Rule<Class_>
 */
final class EnforceBelongsToTenantRule implements Rule
{
    private const MODEL_CLASS = 'Illuminate\Database\Eloquent\Model';

    private const PIVOT_CLASS = 'Illuminate\Database\Eloquent\Relations\Pivot';

    /** @var array<string, true>|null Memoised set of tables that declare a tenant_id column. */
    private ?array $tenantTables = null;

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly string $migrationsPath,
    ) {}

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->namespacedName === null) {
            return [];
        }

        $className = $node->namespacedName->toString();

        if (! $this->reflectionProvider->hasClass($className)) {
            return [];
        }

        $class = $this->reflectionProvider->getClass($className);

        // Only concrete Eloquent models; pivots are join tables, not scoped entities.
        if ($class->isAbstract()
            || ! $class->isSubclassOf(self::MODEL_CLASS)
            || $class->isSubclassOf(self::PIVOT_CLASS)
        ) {
            return [];
        }

        // Documented, auditable opt-out for tables that carry tenant_id but are
        // intentionally not request-scoped.
        if ($class->getNativeReflection()->getAttributes(NotTenantScoped::class) !== []) {
            return [];
        }

        $table = $this->resolveTableName($className, $class->getNativeReflection()->getDefaultProperties());

        if (! isset($this->tenantTables()[$table])) {
            return [];
        }

        if ($this->usesBelongsToTenant($class)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Model %s maps to table "%s", which has a tenant_id column, but does not use the %s trait. '
                .'Without it the tenant global scope is never applied and queries leak across tenants. '
                .'Add the trait, or mark the model #[%s] if it is intentionally not request-scoped.',
                $className,
                $table,
                BelongsToTenant::class,
                NotTenantScoped::class,
            ))
                ->identifier('belongsToTenant.missingTrait')
                ->build(),
        ];
    }

    /**
     * @param  array<string, mixed>  $defaultProperties
     */
    private function resolveTableName(string $className, array $defaultProperties): string
    {
        $explicit = $defaultProperties['table'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        // Mirror Eloquent's default: Str::snake(Str::pluralStudly(class_basename)).
        $basename = ($pos = strrpos($className, '\\')) !== false
            ? substr($className, $pos + 1)
            : $className;

        return str_replace('\\', '', Str::snake(Str::pluralStudly($basename)));
    }

    private function usesBelongsToTenant(ClassReflection $class): bool
    {
        foreach ($class->getTraits(true) as $trait) {
            if ($trait->getName() === BelongsToTenant::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function tenantTables(): array
    {
        if ($this->tenantTables !== null) {
            return $this->tenantTables;
        }

        $tables = [];
        $finder = new NodeFinder;
        $parser = (new ParserFactory)->createForHostVersion();

        foreach (glob(rtrim($this->migrationsPath, '/').'/*.php') ?: [] as $file) {
            $code = file_get_contents($file);

            if ($code === false) {
                continue;
            }

            $stmts = $parser->parse($code) ?? [];

            /** @var StaticCall[] $calls */
            $calls = $finder->findInstanceOf($stmts, StaticCall::class);

            foreach ($calls as $call) {
                if (! $call->name instanceof Identifier
                    || $call->name->toLowerString() !== 'create'
                    || ! $call->class instanceof Name
                    || $call->class->getLast() !== 'Schema'
                    || ! isset($call->args[0])
                    || ! $call->args[0]->value instanceof String_
                ) {
                    continue;
                }

                $table = $call->args[0]->value->value;

                // Does the create() body declare a tenant_id column?
                /** @var String_[] $strings */
                $strings = $finder->findInstanceOf($call->args, String_::class);

                foreach ($strings as $string) {
                    if ($string->value === 'tenant_id') {
                        $tables[$table] = true;
                        break;
                    }
                }
            }
        }

        return $this->tenantTables = $tables;
    }
}
