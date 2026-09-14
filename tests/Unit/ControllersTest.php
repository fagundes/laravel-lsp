<?php

use App\Lsp\Data\Controllers;
use App\Lsp\Project;
use App\Lsp\Support\Pattern;
use Illuminate\Filesystem\Filesystem;

function controllersProject(string $root): Project
{
    return projectWithModulesContext($root);
}

function writeController(string $path, string $namespace, string $class, string $method): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, <<<PHP
    <?php

    namespace {$namespace};

    class {$class} extends Controller
    {
        public function __construct() {}

        public function {$method}() {}
    }
    PHP);
}

test('discovers controller actions in the application and modules', function () {
    $root = sys_get_temp_dir() . '/lsp-controllers-' . getmypid() . '-' . bin2hex(random_bytes(4));

    try {
        writeController(
            $root . '/app/Http/Controllers/AppController.php',
            'App\\Http\\Controllers',
            'AppController',
            'index',
        );
        writeController(
            $root . '/Modules/Blog/app/Http/Controllers/BlogController.php',
            'Modules\\Blog\\App\\Http\\Controllers',
            'BlogController',
            'show',
        );
        writeController(
            $root . '/Modules/Shop/Http/Controllers/ShopController.php',
            'Modules\\Shop\\Http\\Controllers',
            'ShopController',
            'store',
        );

        expect((new Controllers(controllersProject($root)))->get()->all())
            ->toContain('AppController@index')
            ->toContain('BlogController@show')
            ->toContain('ShopController@store');
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

test('watches modern and legacy module controller directories', function () {
    $patterns = (new Controllers(controllersProject('/workspace')))->patterns();

    expect(Pattern::matchesAny('Modules/Blog/app/Http/Controllers/BlogController.php', $patterns))->toBeTrue()
        ->and(Pattern::matchesAny('Modules/Shop/Http/Controllers/Admin/ShopController.php', $patterns))->toBeTrue()
        ->and(Pattern::matchesAny('Modules/Blog/app/Models/Post.php', $patterns))->toBeFalse();
});
