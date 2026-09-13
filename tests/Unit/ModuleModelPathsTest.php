<?php

use App\Lsp\Data\Auth;
use App\Lsp\Data\Models;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Pattern;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;

function moduleModelsProject(string $root): Project
{
    return new Project(
        FileUri::fromPath($root),
        [],
        new ProjectIndex(new Container),
        new ScriptRunner($root, [PHP_BINARY]),
    );
}

test('resolves modern and legacy model directories from conventional modules', function () {
    require_once app_path('Lsp/Data/Templates/global.php');

    $root = sys_get_temp_dir() . '/lsp-module-models-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $config = app('config');
    $hadModulesConfig = $config->has('modules');
    $originalModulesConfig = $config->get('modules');
    $directories = [
        $root . '/Modules/Blog/app/Models',
        $root . '/Modules/Shop/Entities',
        $root . '/Modules/Billing/Models',
    ];

    try {
        foreach ($directories as $directory) {
            mkdir($directory, 0777, true);
        }

        $config->set('modules.paths.modules', $root . '/Modules');
        $config->set('modules.paths.generator.model.path', 'app/Models');

        expect(collect(LspHelper::modulePaths('model', ['app/Models', 'Entities', 'Models']))->sort()->values()->all())
            ->toBe(collect($directories)->sort()->values()->all());
    } finally {
        if ($hadModulesConfig) {
            $config->set('modules', $originalModulesConfig);
        } else {
            $config->offsetUnset('modules');
        }

        (new Filesystem)->deleteDirectory($root);
    }
});

test('models and auth watch module model directories', function () {
    $project = moduleModelsProject('/workspace');
    $modelPatterns = (new Models($project))->patterns();
    $authPatterns = (new Auth($project))->patterns();

    foreach ([$modelPatterns, $authPatterns] as $patterns) {
        expect(Pattern::matchesAny('Modules/Blog/app/Models/Post.php', $patterns))->toBeTrue()
            ->and(Pattern::matchesAny('Modules/Shop/Entities/Product.php', $patterns))->toBeTrue()
            ->and(Pattern::matchesAny('Modules/Billing/Models/Invoice.php', $patterns))->toBeTrue()
            ->and(Pattern::matchesAny('Modules/Blog/app/Http/Controllers/PostController.php', $patterns))->toBeFalse();
    }
});
