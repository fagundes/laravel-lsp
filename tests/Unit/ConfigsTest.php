<?php

use App\Lsp\Data\Configs;
use App\Lsp\Project;
use App\Lsp\Support\Pattern;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

function configsProject(string $root, array $init = []): Project
{
    return projectWithModulesContext($root, $init);
}

test('watches modern and legacy module config directories', function () {
    $patterns = (new Configs(configsProject('/workspace')))->patterns();

    expect(Pattern::matchesAny('Modules/Blog/config/config.php', $patterns))->toBeTrue()
        ->and(Pattern::matchesAny('Modules/Shop/Config/services.php', $patterns))->toBeTrue()
        ->and(Pattern::matchesAny('Modules/Blog/app/Models/Post.php', $patterns))->toBeFalse();
});

test('locates values loaded from a module config file', function () {
    require_once app_path('Lsp/Data/Templates/global.php');

    $root = sys_get_temp_dir() . '/lsp-configs-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $module = 'LspConfigFixture' . bin2hex(random_bytes(4));
    $key = Str::lower($module);
    $path = $root . '/Modules/' . $module . '/config/config.php';
    $originalBasePath = base_path();
    $config = app('config');
    $hadModulesConfig = $config->has('modules');
    $originalModulesConfig = $config->get('modules');
    $outputLevel = ob_get_level();

    mkdir(dirname($path), 0777, true);
    mkdir($root . '/config', 0777, true);
    file_put_contents($path, <<<'PHP'
    <?php

    return [
        'enabled' => true,
    ];
    PHP);

    try {
        app()->setBasePath($root);
        $config->set('modules.paths.modules', $root . '/Modules');
        $config->set($key, ['enabled' => true]);

        $template = (new Configs(configsProject($root, ['modulesRoot' => 'Modules'])))->template();
        $templatePath = $root . '/configs-template.php';
        file_put_contents($templatePath, $template);

        ob_start();
        include $templatePath;
        $data = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);

        expect(collect($data)->firstWhere('name', $key . '.enabled'))
            ->toMatchArray([
                'name'  => $key . '.enabled',
                'value' => true,
                'file'  => 'Modules/' . $module . '/config/config.php',
            ]);
    } finally {
        app()->setBasePath($originalBasePath);

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
