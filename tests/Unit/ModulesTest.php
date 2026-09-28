<?php

use App\Lsp\Data\AppBindings;
use App\Lsp\Data\Auth;
use App\Lsp\Data\BladeComponents;
use App\Lsp\Data\Configs;
use App\Lsp\Data\Controllers;
use App\Lsp\Data\CustomBladeDirectives;
use App\Lsp\Data\Middleware;
use App\Lsp\Data\Models;
use App\Lsp\Data\Modules;
use App\Lsp\Data\ViewNamespaces;
use App\Lsp\Data\Views;
use App\Lsp\Support\ModulePaths;
use App\Lsp\Support\Pattern;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    require_once app_path('Lsp/Data/Templates/global.php');
});

test('reads module discovery initialization options', function () {
    $defaults = projectWithModulesContext('/workspace');
    $configured = projectWithModulesContext('/workspace', [
        'modulesEnabled'             => false,
        'modulesRoot'                => 'packages/modules',
        'modelPaths'                 => ['app/Domain', '', 'app/Domain'],
        'mixinPaths'                 => ['_ide_helper_models.php', '', '_ide_helper_models.php'],
        'eloquentDatabaseInspection' => false,
    ]);

    expect($defaults->modulesEnabled())->toBeTrue()
        ->and($defaults->eloquentDatabaseInspection())->toBeTrue()
        ->and($defaults->modulesRoot())->toBeNull()
        ->and($defaults->modelPaths())->toBe(['app/Models'])
        ->and($configured->modulesEnabled())->toBeFalse()
        ->and($configured->eloquentDatabaseInspection())->toBeFalse()
        ->and($configured->modulesRoot())->toBe('packages/modules')
        ->and($configured->modelPaths())->toBe(['app/Domain'])
        ->and($configured->mixinPaths())->toBe(['_ide_helper_models.php']);
});

test('parses module context and returns an empty context when disabled', function () {
    $project = projectWithModulesContext('/workspace');
    $provider = new Modules($project);
    $module = [
        'name'      => 'Blog',
        'namespace' => 'Modules\\Blog',
        'path'      => 'Modules/Blog',
        'paths'     => ['models' => 'app/Models'],
    ];

    expect($provider->parse([
        'enabled'   => true,
        'root'      => 'Modules',
        'namespace' => 'Modules',
        'modules'   => [$module, 'invalid'],
    ]))->toMatchArray([
        'enabled'   => true,
        'root'      => 'Modules',
        'namespace' => 'Modules',
        'modules'   => [$module],
    ])->and($provider->parse(['enabled' => false]))->toBe(Modules::empty());
});

test('discovers modules from an explicit root with modern and legacy layouts', function () {
    $root = sys_get_temp_dir() . '/lsp-modules-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $originalBasePath = base_path();
    $config = app('config');
    $hadModulesConfig = $config->has('modules');
    $originalModulesConfig = $config->get('modules');

    try {
        mkdir($root . '/packages/modules/Blog/app/Models', 0777, true);
        mkdir($root . '/packages/modules/Shop/Entities', 0777, true);
        app()->setBasePath($root);
        $config->offsetUnset('modules');

        $context = LspHelper::modulesContext('packages/modules');

        expect($context['enabled'])->toBeTrue()
            ->and($context['root'])->toBe('packages/modules')
            ->and($context['namespace'])->toBe('Modules')
            ->and(collect($context['modules'])->pluck('name')->all())->toBe(['Blog', 'Shop'])
            ->and(collect($context['modules'])->pluck('paths.models')->unique()->all())->toBe(['app/Models'])
            ->and(collect(LspHelper::modulePaths(
                'models',
                ['app/Models', 'Entities', 'Models'],
                'packages/modules',
            ))->sort()->values()->all())->toBe([
                $root . '/packages/modules/Blog/app/Models',
                $root . '/packages/modules/Shop/Entities',
            ]);
    } finally {
        app()->setBasePath($originalBasePath);

        if ($hadModulesConfig) {
            $config->set('modules', $originalModulesConfig);
        } else {
            $config->offsetUnset('modules');
        }

        (new Filesystem)->deleteDirectory($root);
    }
});

test('prefers the nwidart repository and respects generator configuration', function () {
    $root = sys_get_temp_dir() . '/lsp-nwidart-modules-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $originalBasePath = base_path();
    $config = app('config');
    $hadModulesConfig = $config->has('modules');
    $originalModulesConfig = $config->get('modules');
    $hadRepository = app()->bound('modules');
    $originalRepository = $hadRepository ? app('modules') : null;

    $module = new class($root)
    {
        public function __construct(private string $root) {}

        public function getName(): string
        {
            return 'sales';
        }

        public function getStudlyName(): string
        {
            return 'Sales';
        }

        public function getPath(): string
        {
            return $this->root . '/DomainModules/Sales';
        }

        public function isEnabled(): bool
        {
            return false;
        }
    };
    $repository = new class($module)
    {
        public function __construct(private object $module) {}

        public function all(): array
        {
            return [$this->module];
        }
    };

    try {
        mkdir($root . '/DomainModules/Sales/src/Models', 0777, true);
        app()->setBasePath($root);
        app()->instance('modules', $repository);
        $config->set('modules.namespace', 'DomainModules');
        $config->set('modules.paths.modules', $root . '/DomainModules');
        $config->set('modules.paths.generator.model.path', 'src/Models');

        $context = LspHelper::modulesContext('ignored/fallback');

        expect($context['root'])->toBe('DomainModules')
            ->and($context['namespace'])->toBe('DomainModules')
            ->and($context['modules'][0])->toMatchArray([
                'name'       => 'sales',
                'studlyName' => 'Sales',
                'namespace'  => 'DomainModules\\Sales',
                'path'       => 'DomainModules/Sales',
                'enabled'    => false,
            ])->and($context['modules'][0]['paths']['models'])->toBe('src/Models');
    } finally {
        app()->setBasePath($originalBasePath);

        if ($hadRepository) {
            app()->instance('modules', $originalRepository);
        } else {
            app()->forgetInstance('modules');
        }

        if ($hadModulesConfig) {
            $config->set('modules', $originalModulesConfig);
        } else {
            $config->offsetUnset('modules');
        }

        (new Filesystem)->deleteDirectory($root);
    }
});

test('falls back to the conventional Modules directory', function () {
    $root = sys_get_temp_dir() . '/lsp-conventional-modules-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $originalBasePath = base_path();
    $config = app('config');
    $hadModulesConfig = $config->has('modules');
    $originalModulesConfig = $config->get('modules');

    try {
        mkdir($root . '/Modules/Inventory', 0777, true);
        app()->setBasePath($root);
        $config->offsetUnset('modules');

        $context = LspHelper::modulesContext();

        expect($context['enabled'])->toBeTrue()
            ->and($context['root'])->toBe('Modules')
            ->and($context['modules'][0]['name'])->toBe('Inventory');
    } finally {
        app()->setBasePath($originalBasePath);

        if ($hadModulesConfig) {
            $config->set('modules', $originalModulesConfig);
        } else {
            $config->offsetUnset('modules');
        }

        (new Filesystem)->deleteDirectory($root);
    }
});

test('uses the resolved module root for paths and context watchers', function () {
    $context = [
        'enabled'   => true,
        'root'      => 'packages/modules',
        'namespace' => 'Modules',
        'modules'   => [[
            'name'       => 'Blog',
            'studlyName' => 'Blog',
            'namespace'  => 'Modules\\Blog',
            'path'       => 'packages/modules/Blog',
            'enabled'    => true,
            'paths'      => ['views' => 'Resources/views'],
        ]],
    ];
    $project = projectWithModulesContext('/workspace', ['modulesRoot' => 'packages/modules'], $context);
    $modulePatterns = (new Modules($project))->patterns();
    $viewPatterns = ModulePaths::patterns($project, 'views', ['resources/views'], '**/*.blade.php');

    expect(Pattern::matchesAny('packages/modules/Blog/module.json', $modulePatterns))->toBeTrue()
        ->and(Pattern::matchesAny('packages/modules/Blog/composer.json', $modulePatterns))->toBeTrue()
        ->and(Pattern::matchesAny('modules_statuses.json', $modulePatterns))->toBeTrue()
        ->and(Pattern::matchesAny('config/modules.php', $modulePatterns))->toBeTrue()
        ->and(Pattern::matchesAny('packages/modules/Blog/Resources/views/index.blade.php', $viewPatterns))->toBeTrue()
        ->and(Pattern::matchesAny('packages/modules/Blog/resources/views/index.blade.php', $viewPatterns))->toBeTrue();
});

test('all module consumers watch paths below the resolved root', function () {
    $context = [
        'enabled'   => true,
        'root'      => 'packages/modules',
        'namespace' => 'Modules',
        'modules'   => [[
            'name'       => 'Blog',
            'studlyName' => 'Blog',
            'namespace'  => 'Modules\\Blog',
            'path'       => 'packages/modules/Blog',
            'enabled'    => true,
            'paths'      => [
                'models'      => 'src/Models',
                'controllers' => 'src/Http/Controllers',
                'providers'   => 'src/Providers',
                'config'      => 'resources/config',
                'views'       => 'resources/templates',
            ],
        ]],
    ];
    $project = projectWithModulesContext('/workspace', ['modulesRoot' => 'packages/modules'], $context);

    $expectations = [
        [new Models($project), 'packages/modules/Blog/src/Models/Post.php'],
        [new Auth($project), 'packages/modules/Blog/src/Models/Post.php'],
        [new Controllers($project), 'packages/modules/Blog/src/Http/Controllers/PostController.php'],
        [new Configs($project), 'packages/modules/Blog/resources/config/services.php'],
        [new Views($project), 'packages/modules/Blog/resources/templates/index.blade.php'],
        [new BladeComponents($project), 'packages/modules/Blog/resources/templates/components/card.blade.php'],
        [new AppBindings($project), 'packages/modules/Blog/src/Providers/AppServiceProvider.php'],
        [new CustomBladeDirectives($project), 'packages/modules/Blog/src/Providers/AppServiceProvider.php'],
        [new Middleware($project), 'packages/modules/Blog/src/Providers/AppServiceProvider.php'],
        [new ViewNamespaces($project), 'packages/modules/Blog/src/Providers/AppServiceProvider.php'],
        [new ViewNamespaces($project), 'packages/modules/Blog/resources/templates/index.blade.php'],
    ];

    foreach ($expectations as [$provider, $path]) {
        expect(Pattern::matchesAny($path, $provider->patterns()))->toBeTrue();
    }
});

test('does not discover modules or register module watchers when disabled', function () {
    $project = projectWithModulesContext('/workspace', ['modulesEnabled' => false]);

    expect(LspHelper::modulesContext(null, false))->toBe(Modules::empty())
        ->and((new Modules($project))->patterns())->toBe([])
        ->and(ModulePaths::patterns($project, 'models', ['app/Models'], '**/*.php'))->toBe([])
        ->and(ModulePaths::directories($project, 'models', ['app/Models']))->toBe([]);
});
