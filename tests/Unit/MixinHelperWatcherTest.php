<?php

use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Pattern;
use App\Lsp\Watchers\MixinHelperWatcher;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

function mixinWatcher(string $root): MixinHelperWatcher
{
    $project = new Project(
        FileUri::fromPath($root),
        [],
        new ProjectIndex(new Container),
        new ScriptRunner($root, [PHP_BINARY]),
    );

    return new class($project) extends MixinHelperWatcher
    {
        public function hosts(): array
        {
            return $this->discoverHosts();
        }

        public function reflected(array $targets): array
        {
            return $this->reflect($targets);
        }

        public function tags(string $target, ?array $model, ?array $reflected): array
        {
            return $this->targetTags($target, $model, $reflected);
        }

        public function helper(array $blocks): string
        {
            return $this->render($blocks);
        }
    };
}

function writeMixinFixture(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, $content);
}

test('discovers mixin hosts outside ignored project directories', function () {
    $root = sys_get_temp_dir() . '/lsp-mixins-' . getmypid() . '-' . bin2hex(random_bytes(4));

    try {
        writeMixinFixture($root . '/app/Services/Host.php', <<<'PHP'
        <?php

        namespace App\Services;

        /**
         * @mixin \App\Services\FirstTarget
         * @mixin App\Services\SecondTarget
         */
        class Host {}
        PHP);
        writeMixinFixture($root . '/vendor/Ignored.php', <<<'PHP'
        <?php

        /** @mixin \Vendor\Target */
        class Ignored {}
        PHP);

        $watcher = mixinWatcher($root);

        expect($watcher->hosts())->toBe([
            'App\Services\Host' => [
                'App\Services\FirstTarget',
                'App\Services\SecondTarget',
            ],
        ])->and(Pattern::matchesAny('app/Services/Host.php', $watcher->patterns()))->toBeTrue()
            ->and(Pattern::matchesAny('vendor/Ignored.php', $watcher->patterns()))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

test('reflects real methods from a mixin target in the Laravel runtime', function () {
    $target = Collection::class;
    $reflected = mixinWatcher(base_path())->reflected([$target]);

    expect($reflected)->toHaveKey($target)
        ->and(collect($reflected[$target]['methods'])->pluck('name'))->toContain('map');
});

test('renders reflected members and keeps the original mixin tag', function () {
    $watcher = mixinWatcher('/workspace');
    $tags = $watcher->tags('App\Services\Target', null, [
        'tags'    => ['@property-read string $name'],
        'methods' => [[
            'name'       => 'find',
            'isStatic'   => true,
            'parameters' => ['int $id'],
            'return'     => '?App\Models\User',
        ]],
    ]);
    $helper = $watcher->helper([
        'App\Facades\Service' => [...$tags, '@mixin \App\Services\Target'],
    ]);

    expect($helper)
        ->toContain('namespace App\Facades {')
        ->toContain('@property-read string $name')
        ->toContain('@method static ?App\Models\User find(int $id)')
        ->toContain('@mixin \App\Services\Target')
        ->toContain('class Service');
});

test('renders dynamic members when the mixin target is an Eloquent model', function () {
    $tags = mixinWatcher('/workspace')->tags('App\Models\Post', [
        'class'      => 'App\Models\Post',
        'attributes' => [[
            'name'       => 'title',
            'title_case' => 'Title',
            'type'       => 'varchar',
            'cast'       => null,
            'nullable'   => false,
            'documented' => false,
        ]],
        'scopes'    => [],
        'relations' => [[
            'name'       => 'comments',
            'snake_case' => 'comments',
            'type'       => 'HasMany',
            'related'    => 'App\Models\Comment',
        ]],
    ], null);

    expect($tags)
        ->toContain('@property string $title')
        ->toContain('@method static \Illuminate\Database\Eloquent\Builder<\App\Models\Post>|\App\Models\Post whereTitle(mixed $value)')
        ->toContain('@property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Comment> $comments')
        ->toContain('@property-read int|null $comments_count');
});
