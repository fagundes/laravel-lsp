<?php

class LspHelper
{
    protected static $moduleGeneratorKeys = [
        'models'      => 'model',
        'controllers' => 'controller',
        'providers'   => 'provider',
        'migrations'  => 'migration',
        'seeders'     => 'seeder',
        'factories'   => 'factory',
        'config'      => 'config',
        'lang'        => 'lang',
        'views'       => 'views',
        'routes'      => 'routes',
    ];

    protected static $moduleGeneratorFallbacks = [
        'models'      => 'app/Models',
        'controllers' => 'app/Http/Controllers',
        'providers'   => 'app/Providers',
        'migrations'  => 'database/migrations',
        'seeders'     => 'database/seeders',
        'factories'   => 'database/factories',
        'config'      => 'config',
        'lang'        => 'lang',
        'views'       => 'resources/views',
        'routes'      => 'routes',
    ];

    /**
     * Describe the modules available in the Laravel application.
     */
    public static function modulesContext($overrideRoot = null, $enabled = true)
    {
        $empty = ['enabled' => false, 'root' => null, 'namespace' => null, 'modules' => []];

        if (!$enabled) {
            return $empty;
        }

        $namespace = config('modules.namespace', 'Modules');
        $namespace = is_string($namespace) && trim($namespace, '\\') !== ''
            ? trim($namespace, '\\')
            : 'Modules';

        if (app()->bound('modules')) {
            $root = config('modules.paths.modules', base_path('Modules'));
            $modules = collect(app('modules')->all())
                ->filter(fn ($module) => is_object($module) && method_exists($module, 'getPath'))
                ->map(fn ($module) => self::describeModule(
                    method_exists($module, 'getName') ? $module->getName() : basename($module->getPath()),
                    method_exists($module, 'getStudlyName') ? $module->getStudlyName() : str(basename($module->getPath()))->studly(),
                    $namespace,
                    $module->getPath(),
                    method_exists($module, 'isEnabled') ? $module->isEnabled() : true,
                ))
                ->values()
                ->all();

            return [
                'enabled'   => true,
                'root'      => is_string($root) ? self::relativePath($root) : null,
                'namespace' => $namespace,
                'modules'   => $modules,
            ];
        }

        $root = $overrideRoot ?: config('modules.paths.modules', base_path('Modules'));

        if (!is_string($root) || $root === '') {
            $root = base_path('Modules');
        } elseif (!self::isAbsolutePath($root)) {
            $root = base_path($root);
        }

        if (!is_dir($root)) {
            return $empty;
        }

        $modules = collect(app('files')->directories($root))
            ->map(fn ($directory) => self::describeModule(
                basename($directory),
                str(basename($directory))->studly(),
                $namespace,
                $directory,
                true,
            ))
            ->values()
            ->all();

        return [
            'enabled'   => true,
            'root'      => self::relativePath($root),
            'namespace' => $namespace,
            'modules'   => $modules,
        ];
    }

    /**
     * Resolve existing generator directories across Laravel modules.
     *
     * @param  array<int, string>  $fallbacks
     * @return array<int, string>
     */
    public static function modulePaths($type, $fallbacks = [], $overrideRoot = null, $enabled = true)
    {
        return collect(self::modulesContext($overrideRoot, $enabled)['modules'])
            ->flatMap(function ($module) use ($type, $fallbacks) {
                $base = self::absolutePath($module['path']);
                $paths = collect([$module['paths'][$type] ?? null, ...$fallbacks])
                    ->filter(fn ($path) => is_string($path) && $path !== '')
                    ->unique();

                return $paths->map(fn ($path) => $base . DIRECTORY_SEPARATOR . $path);
            })
            ->filter(fn ($path) => is_dir($path))
            ->unique()
            ->values()
            ->all();
    }

    protected static function describeModule($name, $studlyName, $rootNamespace, $path, $enabled)
    {
        return [
            'name'       => $name,
            'studlyName' => (string) $studlyName,
            'namespace'  => trim($rootNamespace, '\\') . '\\' . $studlyName,
            'path'       => self::relativePath($path),
            'enabled'    => (bool) $enabled,
            'paths'      => self::moduleGeneratorPaths(),
        ];
    }

    protected static function moduleGeneratorPaths()
    {
        return collect(self::$moduleGeneratorKeys)
            ->mapWithKeys(function ($generator, $type) {
                $path = config(
                    "modules.paths.generator.{$generator}.path",
                    self::$moduleGeneratorFallbacks[$type],
                );

                return [
                    $type => is_string($path) && $path !== ''
                        ? trim($path, ' /\\')
                        : self::$moduleGeneratorFallbacks[$type],
                ];
            })
            ->all();
    }

    public static function absolutePath($path)
    {
        return self::isAbsolutePath($path) ? $path : base_path($path);
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
