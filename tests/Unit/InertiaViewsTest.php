<?php

use App\Lsp\Data\InertiaViews;
use Illuminate\Filesystem\Filesystem;

test('discovers inertia pages inside enabled modules', function () {
    $root = sys_get_temp_dir() . '/lsp-inertia-modules-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $filesystem = new Filesystem;

    try {
        $filesystem->ensureDirectoryExists($root . '/Modules/Blog/resources/js/Pages');
        file_put_contents($root . '/Modules/Blog/resources/js/Pages/Dashboard.vue', '<template />');

        $project = projectWithModulesContext($root);
        $provider = new InertiaViews($project);
        $result = $provider->parse(['page_extensions' => ['vue']]);

        expect($result['page_paths']->all())->toContain('Modules/Blog/resources/js/Pages')
            ->and($result['views']->get('Dashboard'))->toMatchArray([
                'name' => 'Dashboard',
                'path' => 'Modules/Blog/resources/js/Pages/Dashboard.vue',
            ])
            ->and($provider->patterns())->toContain('Modules/*/resources/js/Pages/{*,**/*}');
    } finally {
        $filesystem->deleteDirectory($root);
    }
});

test('does not add module inertia paths when modules are disabled', function () {
    $project = projectWithModulesContext('/workspace', ['modulesEnabled' => false]);
    $provider = new InertiaViews($project);

    expect($provider->parse([])['page_paths']->all())->toBe(['resources/js/Pages'])
        ->and($provider->patterns())->not->toContain('Modules/*/resources/js/Pages/{*,**/*}');
});
