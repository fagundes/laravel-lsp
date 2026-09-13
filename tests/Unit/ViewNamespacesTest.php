<?php

use App\Lsp\Data\ViewNamespaces;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

test('parses valid view namespace roots', function () {
    $container = new Container;
    $project = new Project(
        FileUri::fromPath('/workspace'),
        [],
        new ProjectIndex($container),
        new ScriptRunner('/workspace', ['php']),
    );

    $namespaces = (new ViewNamespaces($project))->parse([
        'blog' => [
            ['path' => 'Modules/Blog/resources/views', 'isVendor' => false],
            ['path' => 'vendor/acme/blog/resources/views', 'isVendor' => true],
            ['path' => null],
        ],
        'invalid' => 'not-an-array',
    ]);

    expect($namespaces)->toBe([
        'blog' => [
            ['path' => 'Modules/Blog/resources/views', 'isVendor' => false],
            ['path' => 'vendor/acme/blog/resources/views', 'isVendor' => true],
        ],
    ]);
});
