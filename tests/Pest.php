<?php

use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something(): void
// {
//     // ..
// }

/**
 * Build a project with a deterministic modules context for unit tests.
 *
 * @param  array<string, mixed>  $init
 * @param  array<string, mixed>|null  $context
 */
function projectWithModulesContext(string $root, array $init = [], ?array $context = null): Project
{
    $container = new Container;
    $moduleRoot = $init['modulesRoot'] ?? 'Modules';
    $absoluteRoot = str_starts_with($moduleRoot, DIRECTORY_SEPARATOR) ? $moduleRoot : $root . '/' . $moduleRoot;
    $modules = collect(glob($absoluteRoot . '/*', GLOB_ONLYDIR) ?: [])->map(fn (string $path): array => [
        'name'       => basename($path),
        'studlyName' => basename($path),
        'namespace'  => 'Modules\\' . basename($path),
        'path'       => trim($moduleRoot, ' /\\') . '/' . basename($path),
        'enabled'    => true,
        'paths'      => [
            'models'      => 'app/Models',
            'controllers' => 'app/Http/Controllers',
            'providers'   => 'app/Providers',
            'config'      => 'config',
            'views'       => 'resources/views',
        ],
    ])->values()->all();
    $context ??= [
        'enabled'   => ($init['modulesEnabled'] ?? true) === true,
        'root'      => trim($moduleRoot, ' /\\'),
        'namespace' => 'Modules',
        'modules'   => $modules,
    ];
    $index = new class($container, $context) extends ProjectIndex
    {
        public function __construct(Container $container, protected array $context)
        {
            parent::__construct($container);
        }

        public function modules(): array
        {
            return $this->context;
        }
    };
    $project = new Project(
        FileUri::fromPath($root),
        $init,
        $index,
        new ScriptRunner($root, [PHP_BINARY]),
    );
    $container->instance(Project::class, $project);

    return $project;
}
