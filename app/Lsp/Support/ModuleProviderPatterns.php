<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use App\Lsp\Project;

final class ModuleProviderPatterns
{
    /**
     * Add conventional Laravel module provider paths to watcher patterns.
     *
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    public static function merge(Project $project, array $patterns): array
    {
        return [
            ...$patterns,
            ...ModulePaths::patterns(
                $project,
                'providers',
                ['app/Providers', 'Providers'],
                '{,*,**/*}.php',
            ),
        ];
    }
}
