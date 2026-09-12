<?php

use App\Lsp\Support\ModuleProviderPatterns;
use App\Lsp\Support\Pattern;

test('adds modern and legacy module provider watcher patterns', function () {
    $patterns = ModuleProviderPatterns::merge([
        'app/Providers/{,*,**/*}.php',
    ]);

    expect($patterns)
        ->toContain('app/Providers/{,*,**/*}.php')
        ->and(Pattern::matches('Modules/Blog/app/Providers/BlogServiceProvider.php', $patterns[1]))->toBeTrue()
        ->and(Pattern::matches('Modules/Blog/Providers/BlogServiceProvider.php', $patterns[2]))->toBeTrue();
});

test('does not match files outside module provider directories', function () {
    $patterns = ModuleProviderPatterns::merge([]);

    expect(Pattern::matchesAny('Modules/Blog/app/Models/Post.php', $patterns))->toBeFalse()
        ->and(Pattern::matchesAny('vendor/acme/package/src/Providers/PackageServiceProvider.php', $patterns))->toBeFalse();
});
