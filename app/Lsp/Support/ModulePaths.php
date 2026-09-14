<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use App\Lsp\Project;

final class ModulePaths
{
    /**
     * Resolve existing absolute directories for a module generator type.
     *
     * @param  array<int, string>  $fallbacks
     * @return array<int, string>
     */
    public static function directories(Project $project, string $type, array $fallbacks = []): array
    {
        return collect(self::relativeDirectories($project, $type, $fallbacks))
            ->map(fn (string $path): string => self::absolutePath($project, $path))
            ->filter(fn (string $path): bool => is_dir($path))
            ->values()
            ->all();
    }

    /**
     * Build watcher patterns for a module generator type.
     *
     * @param  array<int, string>  $fallbacks
     * @return array<int, string>
     */
    public static function patterns(Project $project, string $type, array $fallbacks, string $files): array
    {
        if (!$project->modulesEnabled()) {
            return [];
        }

        $context = $project->index->modules();
        $root = is_string($context['root'] ?? null)
            ? str_replace('\\', '/', trim($context['root'], ' /\\'))
            : ($project->modulesRoot() ?? 'Modules');
        $paths = collect($context['modules'] ?? [])
            ->pluck("paths.{$type}")
            ->merge($fallbacks)
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->map(fn (string $path): string => trim(str_replace('\\', '/', $path), '/'))
            ->unique();

        return $paths->map(fn (string $path): string => "{$root}/*/{$path}/{$files}")->values()->all();
    }

    /**
     * Resolve workspace-relative module directories.
     *
     * @param  array<int, string>  $fallbacks
     * @return array<int, string>
     */
    protected static function relativeDirectories(Project $project, string $type, array $fallbacks): array
    {
        if (!$project->modulesEnabled()) {
            return [];
        }

        return collect($project->index->modules()['modules'] ?? [])
            ->filter(fn (mixed $module): bool => is_array($module) && is_string($module['path'] ?? null))
            ->flatMap(function (array $module) use ($type, $fallbacks): array {
                return collect([$module['paths'][$type] ?? null, ...$fallbacks])
                    ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
                    ->map(fn (string $path): string => str_replace('\\', '/', trim($module['path'], ' /\\') . '/' . trim($path, ' /\\')))
                    ->unique()
                    ->all();
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resolve an absolute module path without rebasing paths outside the workspace.
     */
    protected static function absolutePath(Project $project, string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
                ? $path
                : $project->path($path);
    }
}
