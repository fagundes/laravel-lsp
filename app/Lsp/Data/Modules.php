<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;

class Modules implements DataProvider
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get the default empty modules context.
     *
     * @return array{enabled: bool, root: null, namespace: null, modules: array<int, mixed>}
     */
    public static function empty(): array
    {
        return [
            'enabled'   => false,
            'root'      => null,
            'namespace' => null,
            'modules'   => [],
        ];
    }

    /**
     * Get the modules discovery template.
     */
    public function template(): string
    {
        $template = file_get_contents(__DIR__ . '/Templates/modules.php') ?: '';

        return str_replace(
            ['__LARAVEL_LSP_MODULES_ROOT__', '__LARAVEL_LSP_MODULES_ENABLED__'],
            [var_export($this->project->modulesRoot(), true), $this->project->modulesEnabled() ? 'true' : 'false'],
            $template,
        );
    }

    /**
     * Parse the raw modules context.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function parse(array $data): array
    {
        if (!($data['enabled'] ?? false)) {
            return self::empty();
        }

        return [
            'enabled'   => true,
            'root'      => is_string($data['root'] ?? null) ? $data['root'] : null,
            'namespace' => is_string($data['namespace'] ?? null) ? $data['namespace'] : 'Modules',
            'modules'   => collect($data['modules'] ?? [])
                ->filter(fn (mixed $module): bool => is_array($module))
                ->values()
                ->all(),
        ];
    }

    /**
     * Get the resolved modules context.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        if (!$this->project->modulesEnabled()) {
            return self::empty();
        }

        $data = $this->project->scripts->json($this->template());

        return $this->parse(is_array($data) ? $data : []);
    }

    /**
     * Get module context watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        if (!$this->project->modulesEnabled()) {
            return [];
        }

        $context = $this->project->index->modules();
        $root = is_string($context['root'] ?? null)
            ? str_replace('\\', '/', trim($context['root'], ' /\\'))
            : ($this->project->modulesRoot() ?? 'Modules');

        return [
            "{$root}/*/module.json",
            "{$root}/*/composer.json",
            'modules_statuses.json',
            'config/modules.php',
        ];
    }
}
