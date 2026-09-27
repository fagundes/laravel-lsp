<?php

use phpDocumentor\Reflection\DocBlockFactory;

$targets = collect(__LARAVEL_LSP_MIXIN_TARGETS__)->unique()->values();
$files = collect(__LARAVEL_LSP_MIXIN_FILES__)->unique()->values();

$files->each(function (mixed $file): void {
    if (!is_string($file) || !is_file($file)) {
        return;
    }

    try {
        require_once $file;
    } catch (Throwable $e) {
        report($e);
    }
});

$factory = class_exists(DocBlockFactory::class) ? DocBlockFactory::createInstance() : null;

$docblocks = new class($factory)
{
    public function __construct(protected $factory) {}

    public function forMethod(ReflectionMethod $method): array
    {
        if ($this->factory !== null) {
            try {
                $docblock = $this->factory->create($method->getDocComment() ?: '/** */');
                $params = collect($docblock->getTagsByName('param'))->map(fn ($param) => (string) $param)->all();
                $return = $docblock->getTagsByName('return')[0] ?? null;

                return [$params, $return === null ? null : (string) $return];
            } catch (Throwable) {
                // Fall back to native reflection for malformed docblocks.
            }
        }

        $params = collect($method->getParameters())
            ->map(function (ReflectionParameter $parameter): string {
                $type = $this->type($parameter->getType());
                $prefix = $type === null ? '' : $type . ' ';

                return $prefix
                    . ($parameter->isPassedByReference() ? '&' : '')
                    . ($parameter->isVariadic() ? '...' : '')
                    . '$' . $parameter->getName();
            })
            ->all();

        return [$params, $this->type($method->getReturnType())];
    }

    public function existingTags(ReflectionClass $reflection): array
    {
        if ($this->factory === null || !$reflection->getDocComment()) {
            return [];
        }

        try {
            $docblock = $this->factory->create($reflection->getDocComment());
        } catch (Throwable) {
            return [];
        }

        return collect(['property', 'property-read', 'property-write', 'method'])
            ->flatMap(fn (string $name) => collect($docblock->getTagsByName($name))
                ->map(fn ($tag): string => '@' . $name . ' ' . (string) $tag))
            ->values()
            ->all();
    }

    protected function type(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $separator = $type instanceof ReflectionUnionType ? '|' : '&';

            return collect($type->getTypes())->map(fn (ReflectionType $part) => $this->type($part))->join($separator);
        }

        $name = $type->getName();

        if (!$type->isBuiltin() && !in_array($name, ['self', 'parent', 'static'], true)) {
            $name = '\\' . $name;
        }

        return $type->allowsNull() && !in_array($name, ['mixed', 'null'], true)
            ? '?' . $name
            : $name;
    }
};

$results = $targets->mapWithKeys(function (string $class) use ($docblocks): array {
    if (!class_exists($class)) {
        return [$class => null];
    }

    try {
        $reflection = new ReflectionClass($class);
    } catch (Throwable) {
        return [$class => null];
    }

    $methods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED))
        ->reject(fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), '__'))
        ->map(function (ReflectionMethod $method) use ($docblocks): array {
            [$parameters, $return] = $docblocks->forMethod($method);

            return [
                'name'       => $method->getName(),
                'isStatic'   => $method->isStatic(),
                'parameters' => $parameters,
                'return'     => $return,
            ];
        })
        ->values();

    return [$class => [
        'methods' => $methods,
        'tags'    => $docblocks->existingTags($reflection),
    ]];
});

echo json_encode($results);
