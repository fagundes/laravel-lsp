<?php

use App\Lsp\Features\Views\ViewCodeActionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

function viewCodeActionProject(array $viewNamespaces = []): Project
{
    $index = new class(new Container, $viewNamespaces) extends ProjectIndex
    {
        public function __construct(Container $container, protected array $roots)
        {
            parent::__construct($container);
        }

        public function viewNamespaces(): array
        {
            return $this->roots;
        }
    };

    return new Project(
        FileUri::fromPath('/workspace'),
        [],
        $index,
        new ScriptRunner('/workspace', ['php']),
    );
}

function createViewAction(Project $project, string $view): ?array
{
    $provider = new class($project) extends ViewCodeActionProvider
    {
        public function action(string $view): ?array
        {
            return $this->createViewAction($view, ['code' => 'view']);
        }
    };

    return $provider->action($view);
}

test('creates regular views in the application view directory', function () {
    $action = createViewAction(viewCodeActionProject(), 'admin.dashboard');

    expect($action['edit']['documentChanges'][0]['uri'])
        ->toBe('file:///workspace/resources/views/admin/dashboard.blade.php');
});

test('creates namespaced views in the first non-vendor namespace root', function () {
    $project = viewCodeActionProject([
        'blog' => [
            ['path' => 'vendor/acme/blog/resources/views', 'isVendor' => true],
            ['path' => 'Modules/Blog/resources/views', 'isVendor' => false],
        ],
    ]);

    $action = createViewAction($project, 'blog::admin.dashboard');

    expect($action['edit']['documentChanges'][0]['uri'])
        ->toBe('file:///workspace/Modules/Blog/resources/views/admin/dashboard.blade.php');
});

test('does not offer to create a namespaced view without a local root', function () {
    $project = viewCodeActionProject([
        'blog' => [
            ['path' => 'vendor/acme/blog/resources/views', 'isVendor' => true],
        ],
    ]);

    expect(createViewAction($project, 'blog::dashboard'))->toBeNull();
});
