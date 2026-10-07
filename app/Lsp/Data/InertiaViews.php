<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class InertiaViews implements DataProvider
{
    /**
     * Create a new inertia views provider instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get the inertia template to run.
     */
    public function template(): string
    {
        return file_get_contents(__DIR__ . '/Templates/inertia.php') ?: '';
    }

    /**
     * Parse the raw inertia config data.
     *
     * @param  array<string, mixed>  $data
     * @return array{views: Collection<string, array<string, string>>, page_paths: Collection<int, string>, page_extensions: Collection<int, string>}
     */
    public function parse(array $data): array
    {
        $paths = $this->normalizePagePaths($data)
            ->merge($this->modulePagePaths())
            ->unique()
            ->values();
        $extensions = $this->normalizePageExtensions($data);

        return [
            'views' => $paths
                ->flatMap(fn (string $path): Collection => $this->discoverViews($path, $extensions))
                ->keyBy('name'),
            'page_paths'      => $paths,
            'page_extensions' => $extensions,
        ];
    }

    /**
     * Get data.
     *
     * @return array{views: Collection<string, array<string, string>>, page_paths: Collection<int, string>, page_extensions: Collection<int, string>}
     */
    public function get(): array
    {
        $data = $this->project->scripts->json($this->template());

        return $this->parse(is_array($data) ? $data : []);
    }

    /**
     * Get inertia-related watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        $patterns = [
            'resources/js/Pages/{*,**/*}',
            'resources/js/pages/{*,**/*}',
            'config/{,*,**/*}.php',
        ];

        $moduleRoot = $this->project->index->modules()['root'] ?? 'Modules';

        if (($this->project->index->modules()['enabled'] ?? false) === true) {
            $patterns[] = trim($moduleRoot, '/ ') . '/*/resources/js/Pages/{*,**/*}';
            $patterns[] = trim($moduleRoot, '/ ') . '/*/resources/js/pages/{*,**/*}';
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Normalize Inertia page paths.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, string>
     */
    protected function normalizePagePaths(array $data): Collection
    {
        $paths = collect($data['page_paths'] ?? [])
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->values();

        return $paths->isEmpty() ? collect(['resources/js/Pages']) : $paths;
    }

    /**
     * Normalize Inertia page extensions.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, string>
     */
    protected function normalizePageExtensions(array $data): Collection
    {
        $extensions = collect($data['page_extensions'] ?? [])
            ->filter(fn (mixed $extension): bool => is_string($extension) && $extension !== '')
            ->map(fn (string $extension): string => ltrim($extension, '.'))
            ->values();

        return $extensions->isEmpty() ? collect(['vue']) : $extensions;
    }

    /**
     * Get the conventional Inertia page paths for discovered modules.
     *
     * Module packages commonly keep their frontend pages under either
     * `resources/js/Pages` or `resources/js/pages`, mirroring Laravel's
     * root-level conventions. These paths are added in addition to any
     * paths returned by the application's Inertia configuration.
     *
     * @return Collection<int, string>
     */
    protected function modulePagePaths(): Collection
    {
        $context = $this->project->index->modules();

        if (($context['enabled'] ?? false) !== true) {
            return collect();
        }

        return collect($context['modules'] ?? [])
            ->filter(fn (mixed $module): bool => is_array($module) && ($module['enabled'] ?? true) === true)
            ->flatMap(function (array $module): array {
                $path = trim((string) ($module['path'] ?? ''), '/ ');

                if ($path === '') {
                    return [];
                }

                return [
                    $path . '/resources/js/Pages',
                    $path . '/resources/js/pages',
                ];
            })
            ->values();
    }

    /**
     * Discover Inertia views under a page path.
     *
     * @param  Collection<int, string>  $extensions
     * @return Collection<int, array<string, string>>
     */
    protected function discoverViews(string $path, Collection $extensions): Collection
    {
        $absolute = $this->project->path($path);

        if (!is_dir($absolute)) {
            return collect();
        }

        return collect(Finder::create()->files()->in($absolute))
            ->filter(fn (SplFileInfo $file): bool => $extensions->contains($file->getExtension()))
            ->map(function (SplFileInfo $file) use ($path): array {
                $relative = $file->getRelativePathname();
                $name = preg_replace('/\.[^.]+$/', '', $relative) ?: $relative;

                return [
                    'name' => str_replace('\\', '/', $name),
                    'path' => trim($path, '/') . '/' . str_replace('\\', '/', $relative),
                ];
            })
            ->values();
    }
}
