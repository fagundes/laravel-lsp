<?php

declare(strict_types=1);

namespace App\Lsp\Watchers;

use App\Lsp\Contracts\FileWatcher;
use App\Lsp\Project;
use Symfony\Component\Finder\Finder;
use Throwable;

class MixinHelperWatcher implements FileWatcher
{
    /** @var array<int, string> */
    protected const IGNORED_DIRECTORIES = ['.git', 'node_modules', 'storage', 'vendor'];

    /**
     * Create a new mixin helper watcher instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get mixin helper watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        if (!$this->enabled()) {
            return [];
        }

        return [
            ...collect($this->sourceDirectories())
                ->map(fn (string $directory): string => $directory . '/{,*,**/*}.php')
                ->all(),
            ...$this->additionalMixinPatterns(),
            'vendor/composer/autoload_*.php',
        ];
    }

    /**
     * Generate the helper after watcher registration.
     */
    public function initialize(): void
    {
        $this->generate();
    }

    /**
     * Regenerate the helper after a relevant file change.
     *
     * @param  array<int, string>  $changes
     */
    public function onFileChange(array $changes): void
    {
        $this->generate();
    }

    /**
     * Determine if mixin docblocks should be generated.
     */
    protected function enabled(): bool
    {
        return $this->project->boolean('eloquentGenerateDocBlocks', true);
    }

    /**
     * Generate the mixin helper file.
     */
    protected function generate(): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            $hosts = $this->discoverHosts();
            $targets = collect($hosts)->flatten()->unique()->values()->all();
            $models = $targets === [] ? [] : $this->project->index->models();
            $externalTargets = array_values(array_filter(
                $targets,
                fn (string $target): bool => !isset($models[$target]),
            ));
            $reflected = $this->reflect($externalTargets);
            $blocks = [];

            foreach ($hosts as $host => $hostTargets) {
                $tags = [];

                foreach ($hostTargets as $target) {
                    $tags = [
                        ...$tags,
                        ...$this->targetTags($target, $models[$target] ?? null, $reflected[$target] ?? null),
                        '@mixin \\' . ltrim($target, '\\'),
                    ];
                }

                $blocks[$host] = array_values(array_unique($tags));
            }

            $path = $this->helperFilePath();

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $this->render($blocks));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Discover classes whose docblocks declare mixin targets.
     *
     * @return array<string, array<int, string>>
     */
    protected function discoverHosts(): array
    {
        $directories = array_map(
            fn (string $directory): string => $this->project->path($directory),
            $this->sourceDirectories(),
        );

        if ($directories === []) {
            return [];
        }

        $hosts = [];
        $files = (new Finder)
            ->files()
            ->name('*.php')
            ->size('< 200K')
            ->ignoreUnreadableDirs()
            ->in($directories);

        foreach ($files as $file) {
            $content = $file->getContents();

            if (!str_contains($content, '@mixin')) {
                continue;
            }

            $host = $this->extractHost($content);

            if ($host !== null) {
                $hosts[$host['class']] = array_values(array_unique([
                    ...($hosts[$host['class']] ?? []),
                    ...$host['targets'],
                ]));
            }
        }

        return $hosts;
    }

    /**
     * Extract the first class and its mixin targets from PHP source.
     *
     * @return array{class: string, targets: array<int, string>}|null
     */
    protected function extractHost(string $content): ?array
    {
        if (!preg_match('/(?:^|\n)[ \t]*(?:(?:abstract|final|readonly)\s+)*class\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)/', $content, $class, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $beforeClass = substr($content, 0, $class[0][1]);
        $start = strrpos($beforeClass, '/**');
        $end = strrpos($beforeClass, '*/');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        preg_match_all('/@mixin\s+(\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\\\\\x80-\xff]*)/', substr($beforeClass, $start, $end - $start), $matches);
        $targets = array_values(array_unique($matches[1] ?? []));

        if ($targets === []) {
            return null;
        }

        preg_match('/(?:^|\n)namespace\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\\\\\x80-\xff]*)\s*[;{]/', $content, $namespace);
        $namespace = $namespace[1] ?? '';
        $imports = $this->imports($beforeClass);

        return [
            'class'   => $namespace !== '' ? $namespace . '\\' . $class[1][0] : $class[1][0],
            'targets' => array_map(
                fn (string $target): string => $this->resolveMixinTarget($target, $namespace, $imports),
                $targets,
            ),
        ];
    }

    /**
     * Get class imports declared before the host class.
     *
     * @return array<string, string>
     */
    protected function imports(string $content): array
    {
        preg_match_all(
            '/(?:^|\n)[ \t]*use\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\\\\\x80-\xff]*)(?:\s+as\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*))?\s*;/i',
            $content,
            $matches,
            PREG_SET_ORDER,
        );

        $imports = [];

        foreach ($matches as $match) {
            $class = $match[1];
            $alias = ($match[2] ?? '') !== '' ? $match[2] : basename(str_replace('\\', '/', $class));
            $imports[$alias] = $class;
        }

        return $imports;
    }

    /**
     * Resolve a PHPDoc class name in the context of its host class.
     *
     * @param  array<string, string>  $imports
     */
    protected function resolveMixinTarget(string $target, string $namespace, array $imports): string
    {
        if (str_starts_with($target, '\\')) {
            return ltrim($target, '\\');
        }

        [$first, $remainder] = array_pad(explode('\\', $target, 2), 2, null);

        if (isset($imports[$first])) {
            return $imports[$first] . ($remainder === null ? '' : '\\' . $remainder);
        }

        if (!str_contains($target, '\\')) {
            return $namespace === '' ? $target : $namespace . '\\' . $target;
        }

        return $target;
    }

    /**
     * Reflect non-model mixin targets inside the Laravel application.
     *
     * @param  array<int, string>  $targets
     * @return array<string, mixed>
     */
    protected function reflect(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $template = file_get_contents(__DIR__ . '/../Data/Templates/mixin-targets.php') ?: '';
        $code = str_replace(
            ['__LARAVEL_LSP_MIXIN_TARGETS__', '__LARAVEL_LSP_MIXIN_FILES__'],
            [var_export($targets, true), var_export($this->additionalMixinFiles(), true)],
            $template,
        );
        $result = $this->project->scripts->json($code);

        return is_array($result) ? $result : [];
    }

    /**
     * Render tags for a reflected class or Eloquent model.
     *
     * @param  array<string, mixed>|null  $model
     * @param  array<string, mixed>|null  $reflected
     * @return array<int, string>
     */
    protected function targetTags(string $target, ?array $model, ?array $reflected): array
    {
        if ($model !== null) {
            return $this->modelTags($target, $model);
        }

        if ($reflected === null) {
            return [];
        }

        $methods = collect($reflected['methods'] ?? [])
            ->filter(fn (mixed $method): bool => is_array($method) && is_string($method['name'] ?? null))
            ->map(fn (array $method): string => sprintf(
                '@method %s%s %s(%s)',
                ($method['isStatic'] ?? false) ? 'static ' : '',
                is_string($method['return'] ?? null) && $method['return'] !== '' ? $method['return'] : 'mixed',
                $method['name'],
                collect($method['parameters'] ?? [])->filter(fn (mixed $parameter): bool => is_string($parameter))->join(', '),
            ));

        return collect($reflected['tags'] ?? [])
            ->filter(fn (mixed $tag): bool => is_string($tag))
            ->concat($methods)
            ->values()
            ->all();
    }

    /**
     * Render a model's domain members without query builder proxy methods.
     *
     * @param  array<string, mixed>  $model
     * @return array<int, string>
     */
    protected function modelTags(string $target, array $model): array
    {
        $class = '\\' . ltrim(is_string($model['class'] ?? null) ? $model['class'] : $target, '\\');
        $builder = "\\Illuminate\\Database\\Eloquent\\Builder<{$class}>|{$class}";
        $tags = [];

        foreach ($model['attributes'] ?? [] as $attribute) {
            if (!is_array($attribute) || !is_string($attribute['name'] ?? null)) {
                continue;
            }

            $dynamic = in_array($attribute['cast'] ?? null, ['accessor', 'attribute'], true);

            if (!($attribute['documented'] ?? false)) {
                $type = $this->attributeType($attribute);
                $tags[] = ($dynamic ? '@property-read ' : '@property ') . $type . ' $' . $attribute['name'];
            }

            if (!$dynamic) {
                $method = is_string($attribute['title_case'] ?? null)
                    ? $attribute['title_case']
                    : str($attribute['name'])->studly();
                $tags[] = "@method static {$builder} where{$method}(mixed \$value)";
            }
        }

        foreach ($model['scopes'] ?? [] as $scope) {
            if (!is_array($scope) || !is_string($scope['name'] ?? null)) {
                continue;
            }

            $parameters = collect($scope['parameters'] ?? [])->slice(1)->map(function (array $parameter): string {
                $type = is_string($parameter['type'] ?? null) ? $parameter['type'] : 'mixed';

                return $type . ' '
                    . (($parameter['isPassedByReference'] ?? false) ? '&' : '')
                    . (($parameter['isVariadic'] ?? false) ? '...' : '')
                    . '$' . ($parameter['name'] ?? 'value')
                    . (($parameter['hasDefault'] ?? false) ? ' = ' . ($parameter['default'] ?? 'null') : '');
            })->join(', ');

            $tags[] = "@method static {$builder} {$scope['name']}({$parameters})";
        }

        foreach ($model['relations'] ?? [] as $relation) {
            if (!is_array($relation) || !is_string($relation['name'] ?? null) || !is_string($relation['related'] ?? null)) {
                continue;
            }

            if (in_array($relation['type'] ?? null, ['BelongsToMany', 'HasMany', 'HasManyThrough', 'MorphMany', 'MorphToMany'], true)) {
                $tags[] = "@property-read \\Illuminate\\Database\\Eloquent\\Collection<int, \\{$relation['related']}> \${$relation['name']}";
                $count = is_string($relation['snake_case'] ?? null) ? $relation['snake_case'] : $relation['name'];
                $tags[] = "@property-read int|null \${$count}_count";
            } else {
                $tags[] = "@property-read \\{$relation['related']} \${$relation['name']}";
            }
        }

        return $tags;
    }

    /**
     * Resolve a useful PHP type for a model attribute.
     *
     * @param  array<string, mixed>  $attribute
     */
    protected function attributeType(array $attribute): string
    {
        $cast = is_string($attribute['cast'] ?? null) ? $attribute['cast'] : null;
        $databaseType = is_string($attribute['type'] ?? null) ? $attribute['type'] : '';
        $type = match (true) {
            $cast !== null && str_contains($cast, '\\')                                                            => '\\' . ltrim(strtok($cast, ':'), '\\'),
            in_array($cast, ['array', 'json', 'json:unicode', 'encrypted:json', 'encrypted:array'], true)          => 'array',
            in_array($cast, ['bool', 'boolean'], true)                                                             => 'bool',
            in_array($cast, ['int', 'integer', 'timestamp'], true)                                                 => 'int',
            in_array($cast, ['float', 'double', 'real'], true) || str_starts_with((string) $cast, 'decimal:')      => 'float',
            $cast === 'object' || $cast === 'encrypted:object'                                                     => 'object',
            $cast !== null && !in_array($cast, ['accessor', 'attribute', 'encrypted'], true)                       => (string) strtok($cast, ':'),
            preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|serial|bigserial)/', $databaseType) === 1 => 'int',
            preg_match('/^(float|double|real|decimal|numeric|money)/', $databaseType) === 1                        => 'float',
            preg_match('/^bool/', $databaseType) === 1                                                             => 'bool',
            preg_match('/^(json|text|char|varchar|date|time|uuid|year)/', $databaseType) === 1                     => 'string',
            default                                                                                                => 'mixed',
        };

        return ($attribute['nullable'] ?? false) && $type !== 'mixed' ? $type . '|null' : $type;
    }

    /**
     * Render the generated helper file.
     *
     * @param  array<string, array<int, string>>  $blocks
     */
    protected function render(array $blocks): string
    {
        $content = [
            '<?php',
            '',
            '/**',
            ' * This file is auto-generated by the Laravel VS Code extension.',
            ' * Do not modify this file directly as your changes will be overwritten.',
            ' */',
        ];

        foreach ($blocks as $class => $tags) {
            $parts = explode('\\', ltrim($class, '\\'));
            $name = array_pop($parts);
            $namespace = implode('\\', $parts);
            $declaration = $namespace === '' ? 'namespace {' : "namespace {$namespace} {";
            $docblock = collect($tags)->map(fn (string $tag): string => "     * {$tag}")->join(PHP_EOL);
            $content[] = "{$declaration}\n\n    /**\n{$docblock}\n     */\n    class {$name}\n    {\n        //\n    }\n\n}";
        }

        return implode(PHP_EOL . PHP_EOL, $content) . PHP_EOL;
    }

    /**
     * Get top-level project directories that may contain application classes.
     *
     * @return array<int, string>
     */
    protected function sourceDirectories(): array
    {
        return collect(scandir($this->project->path()) ?: [])
            ->reject(fn (string $entry): bool => $entry === '.' || $entry === '..' || in_array($entry, self::IGNORED_DIRECTORIES, true))
            ->filter(fn (string $entry): bool => is_dir($this->project->path($entry)))
            ->values()
            ->all();
    }

    /**
     * Resolve configured files that may declare mixin targets outside Composer's autoload.
     *
     * @return array<int, string>
     */
    protected function additionalMixinFiles(): array
    {
        return collect($this->project->mixinPaths())
            ->flatMap(function (string $configuredPath): array {
                $path = $this->absolutePath($configuredPath);

                if (is_file($path)) {
                    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php'
                        ? [$this->scriptPath($path)]
                        : [];
                }

                if (!is_dir($path)) {
                    return [];
                }

                return collect((new Finder)
                    ->files()
                    ->name('*.php')
                    ->size('< 2M')
                    ->ignoreUnreadableDirs()
                    ->in($path))
                    ->map(fn ($file): string => $this->scriptPath($file->getRealPath()))
                    ->filter()
                    ->all();
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Use a project-relative path when the PHP environment maps the project elsewhere.
     */
    protected function scriptPath(string $path): string
    {
        $root = str_replace('\\', '/', rtrim(realpath($this->project->path()) ?: $this->project->path(), '/\\'));
        $path = str_replace('\\', '/', realpath($path) ?: $path);

        return str_starts_with($path, $root . '/')
            ? substr($path, strlen($root) + 1)
            : $path;
    }

    /**
     * Get watcher patterns for configured paths inside the project.
     *
     * @return array<int, string>
     */
    protected function additionalMixinPatterns(): array
    {
        return collect($this->project->mixinPaths())
            ->reject(fn (string $path): bool => $this->isAbsolutePath($path))
            ->map(function (string $path): string {
                $path = trim(str_replace('\\', '/', $path), '/');

                return is_dir($this->project->path($path))
                    ? $path . '/{,*,**/*}.php'
                    : $path;
            })
            ->filter(fn (string $path): bool => $path !== '')
            ->values()
            ->all();
    }

    protected function absolutePath(string $path): string
    {
        return $this->isAbsolutePath($path) ? $path : $this->project->path($path);
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * Get the generated helper path.
     */
    protected function helperFilePath(): string
    {
        return $this->project->path('vendor/_laravel_ide/_mixin_helpers.php');
    }
}
