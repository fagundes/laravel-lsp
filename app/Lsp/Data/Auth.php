<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;
use App\Lsp\Support\ModulePaths;
use App\Lsp\Support\ModuleProviderPatterns;

class Auth implements DataProvider
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get the auth template to run.
     */
    public function template(): string
    {
        $template = file_get_contents(__DIR__ . '/Templates/auth.php') ?: '';

        return str_replace(
            ['__LARAVEL_LSP_MODEL_PATHS__', '__LARAVEL_LSP_MODULES_ROOT__', '__LARAVEL_LSP_MODULES_ENABLED__'],
            [var_export($this->project->modelPaths(), true), var_export($this->project->modulesRoot(), true), $this->project->modulesEnabled() ? 'true' : 'false'],
            $template,
        );
    }

    /**
     * Parse the raw auth data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function parse(array $data): array
    {
        return $data;
    }

    /**
     * Get data.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $data = $this->project->scripts->json($this->template());

        return $this->parse(is_array($data) ? $data : []);
    }

    /**
     * Get auth-related watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return ModuleProviderPatterns::merge($this->project, [
            'app/Providers/{,*,**/*}.php',
            ...collect($this->project->modelPaths())->map(fn (string $path): string => "{$path}/{,*,**/*}.php"),
            'app/Policies/{,*,**/*}.php',
            ...ModulePaths::patterns(
                $this->project,
                'models',
                ['app/Models', 'Entities', 'Models'],
                '{,*,**/*}.php',
            ),
        ]);
    }
}
