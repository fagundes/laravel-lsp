<?php

declare(strict_types=1);

namespace App\Lsp\Support;

final class ModuleProviderPatterns
{
    /**
     * Add conventional Laravel module provider paths to watcher patterns.
     *
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    public static function merge(array $patterns): array
    {
        return [
            ...$patterns,
            'Modules/*/app/Providers/{,*,**/*}.php',
            'Modules/*/Providers/{,*,**/*}.php',
        ];
    }
}
