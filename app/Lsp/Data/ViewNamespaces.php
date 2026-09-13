<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;
use App\Lsp\Support\ModuleProviderPatterns;

class ViewNamespaces implements DataProvider
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get the view namespaces template to run.
     */
    public function template(): string
    {
        return file_get_contents(__DIR__ . '/Templates/view-namespaces.php') ?: '';
    }

    /**
     * Parse the raw view namespace data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, array{path: string, isVendor: bool}>>
     */
    public function parse(array $data): array
    {
        return collect($data)
            ->filter(fn (mixed $roots): bool => is_array($roots))
            ->map(fn (array $roots): array => collect($roots)
                ->filter(fn (mixed $root): bool => is_array($root) && is_string($root['path'] ?? null) && $root['path'] !== '')
                ->map(fn (array $root): array => [
                    'path'     => $root['path'],
                    'isVendor' => (bool) ($root['isVendor'] ?? false),
                ])
                ->values()
                ->all())
            ->filter()
            ->all();
    }

    /**
     * Get view namespace roots.
     *
     * @return array<string, array<int, array{path: string, isVendor: bool}>>
     */
    public function get(): array
    {
        $data = $this->project->scripts->json($this->template());

        return $this->parse(is_array($data) ? $data : []);
    }

    /**
     * Get view namespace-related watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return ModuleProviderPatterns::merge([
            'app/Providers/{,*,**/*}.php',
            'config/view.php',
            '**/{resources,Modules/*/resources}/views/**/*.blade.php',
        ]);
    }
}
