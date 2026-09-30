<?php

declare(strict_types=1);

namespace Webong\Cogent\PhpStan;

use Illuminate\Database\Eloquent\Relations\Relation;
use Larastan\Larastan\Methods\Macro;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\ClosureType;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\ObjectType;
use Webong\Cogent\Support\CachedRelationMacro;

/**
 * Teaches PHPStan about the cached() macro that CachedRelationMacro adds to
 * every Eloquent relation, so relation methods that call it stay analysable and
 * keep returning the relation type they were declared with.
 */
final class CachedRelationMacroExtension implements MethodsClassReflectionExtension
{
    public function __construct(
        private readonly ClosureTypeFactory $closureTypeFactory,
    ) {
    }

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $methodName === 'cached' && $classReflection->is(Relation::class);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $closure = $this->closureTypeFactory->fromClosureObject(CachedRelationMacro::closure());

        return new Macro(
            $classReflection,
            $methodName,
            new ClosureType(
                $closure->getParameters(),
                new ObjectType($classReflection->getName()),
                $closure->isVariadic(),
                $closure->getTemplateTypeMap(),
            ),
        );
    }
}
