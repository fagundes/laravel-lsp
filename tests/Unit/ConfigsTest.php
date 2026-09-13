<?php

use App\Lsp\Data\Configs;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Pattern;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

function configsProject(string $root): Project
{
    return new Project(
        FileUri::fromPath($root),
        [],
        new ProjectIndex(new Container),
        new ScriptRunner($root, ['php']),
    );
}

test('watches modern and legacy module config directories', function () {
    $patterns = (new Configs(configsProject('/workspace')))->patterns();

    expect(Pattern::matchesAny('Modules/Blog/config/config.php', $patterns))->toBeTrue()
        ->and(Pattern::matchesAny('Modules/Shop/Config/services.php', $patterns))->toBeTrue()
        ->and(Pattern::matchesAny('Modules/Blog/app/Models/Post.php', $patterns))->toBeFalse();
});

test('locates values loaded from a module config file', function () {
    $root = sys_get_temp_dir() . '/lsp-configs-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $module = 'LspConfigFixture' . bin2hex(random_bytes(4));
    $key = Str::lower($module);
    $path = $root . '/Modules/' . $module . '/config/config.php';
    $config = app('config');
    $hadModulesConfig = $config->has('modules');
    $originalModulesConfig = $config->get('modules');
    $outputLevel = ob_get_level();

    mkdir(dirname($path), 0777, true);
    file_put_contents($path, <<<'PHP'
    <?php

    return [
        'enabled' => true,
    ];
    PHP);

    try {
        $config->set('modules.paths.modules', $root . '/Modules');
        $config->set($key, ['enabled' => true]);

        ob_start();
        include app_path('Lsp/Data/Templates/configs.php');
        $data = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);

        expect(collect($data)->firstWhere('name', $key . '.enabled'))
            ->toMatchArray([
                'name'  => $key . '.enabled',
                'value' => true,
                'file'  => $path,
            ]);
    } finally {
        if (ob_get_level() > $outputLevel) {
            ob_end_clean();
        }

        $config->offsetUnset($key);

        if ($hadModulesConfig) {
            $config->set('modules', $originalModulesConfig);
        } else {
            $config->offsetUnset('modules');
        }

        (new Filesystem)->deleteDirectory($root);
    }
});
