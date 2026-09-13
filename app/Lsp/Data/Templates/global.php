<?php

class LspHelper
{
    /**
     * Resolve existing generator directories across Laravel modules.
     *
     * @param  array<int, string>  $fallbacks
     * @return array<int, string>
     */
    public static function modulePaths($generator, $fallbacks = [])
    {
        $configured = config("modules.paths.generator.{$generator}.path");
        $paths = collect([$configured, ...$fallbacks])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->unique();

        if (app()->bound('modules')) {
            $modules = collect(app('modules')->all())
                ->filter(fn ($module) => is_object($module) && method_exists($module, 'getPath'))
                ->map(fn ($module) => $module->getPath());
        } else {
            $root = config('modules.paths.modules', base_path('Modules'));

            if (is_string($root) && !self::isAbsolutePath($root)) {
                $root = base_path($root);
            }

            $modules = collect(is_string($root) && is_dir($root)
                ? app('files')->directories($root)
                : []);
        }

        return $modules
            ->flatMap(fn ($module) => $paths->map(fn ($path) => $module . DIRECTORY_SEPARATOR . $path))
            ->filter(fn ($path) => is_dir($path))
            ->unique()
            ->values()
            ->all();
    }

    protected static function isAbsolutePath($path)
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    public static function relativePath($path)
    {
        if (!str_contains($path, base_path())) {
            return (string) $path;
        }

        return ltrim(str_replace(base_path(), '', realpath($path) ?: $path), DIRECTORY_SEPARATOR);
    }

    public static function isVendor($path)
    {
        return str_contains($path, base_path('vendor'));
    }

    public static function propertyDefault(ReflectionProperty $property, ?ReflectionParameter $parameter = null): array
    {
        if ($property->hasDefaultValue()) {
            return ['default' => $property->getDefaultValue()];
        }

        if ($parameter?->isDefaultValueAvailable()) {
            return ['default' => $parameter->getDefaultValue()];
        }

        return [];
    }

    public static function formatDefaultValue(mixed $value): mixed
    {
        return match (true) {
            is_array($value)           => 'array(...)',
            $value instanceof UnitEnum => get_class($value) . '::' . $value->name,
            $value instanceof Closure  => 'Closure',
            is_object($value)          => get_class($value),
            is_string($value)          => var_export($value, true),
            is_null($value)            => 'null',
            is_bool($value)            => $value ? 'true' : 'false',
            default                    => $value,
        };
    }
}
