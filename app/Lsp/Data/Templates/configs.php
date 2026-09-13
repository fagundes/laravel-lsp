<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

$local = collect(File::allFiles(config_path()))
    ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
    ->map(fn (SplFileInfo $file) => $file->getPathname())
    ->map(fn ($path) => [
        (string) str($path)
            ->replace([config_path(DIRECTORY_SEPARATOR), '.php'], '')
            ->replace(DIRECTORY_SEPARATOR, '.'),
        $path,
    ]);

$vendor = collect(glob(base_path('vendor/**/**/config/*.php')))->map(fn (
    $path
) => [
    (string) str($path)
        ->afterLast(DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR)
        ->replace('.php', '')
        ->replace(DIRECTORY_SEPARATOR, '.'),
    $path,
]);

$moduleConfigPath = config('modules.paths.generator.config.path', 'config');
$modulesRoot = config('modules.paths.modules', base_path('Modules'));

$moduleDirectories = app()->bound('modules')
    ? collect(app('modules')->all())->map(fn ($module) => [
        'name' => $module->getName(),
        'path' => $module->getPath(),
    ])
    : collect(is_string($modulesRoot) && is_dir($modulesRoot) ? File::directories($modulesRoot) : [])
        ->map(fn (string $path) => [
            'name' => basename($path),
            'path' => $path,
        ]);

$modules = $moduleDirectories->flatMap(function (array $module) use ($moduleConfigPath) {
    $directories = collect([
        is_string($moduleConfigPath) ? $module['path'] . DIRECTORY_SEPARATOR . $moduleConfigPath : null,
        $module['path'] . DIRECTORY_SEPARATOR . 'config',
        $module['path'] . DIRECTORY_SEPARATOR . 'Config',
    ])->filter(fn ($path) => is_string($path) && is_dir($path))->unique();

    return $directories->flatMap(fn (string $directory) => collect(File::files($directory))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->map(function (SplFileInfo $file) use ($module) {
            $basename = $file->getBasename('.php');
            $key = $basename === 'config' ? Str::lower($module['name']) : $basename;

            return [$key, $file->getPathname()];
        }));
});

$configPaths = $local
    ->merge($vendor)
    ->merge($modules)
    ->groupBy(0)
    ->map(fn ($items) =>$items->pluck(1));

$cachedContents = [];
$cachedParsed = [];

function vsCodeGetConfigValue($value, $key, $configPaths)
{
    $parts = explode('.', $key);
    $toFind = $key;
    $found = null;

    while (count($parts) > 0) {
        $toFind = implode('.', $parts);

        if ($configPaths->has($toFind)) {
            $found = $toFind;
            break;
        }

        array_pop($parts);
    }

    if ($found === null) {
        return null;
    }

    $file = null;
    $line = null;

    if ($found === $key) {
        $file = $configPaths->get($found)[0];
    } else {
        foreach ($configPaths->get($found) as $path) {
            $cachedContents[$path] ??= file_get_contents($path);
            $cachedParsed[$path] ??= token_get_all($cachedContents[$path]);

            $keysToFind = str($key)
                ->replaceFirst($found, '')
                ->ltrim('.')
                ->explode('.');

            if (is_numeric($keysToFind->last())) {
                $index = $keysToFind->pop();

                if ($index !== '0') {
                    return null;
                }

                $key = collect(explode('.', $key));
                $key->pop();
                $key = $key->implode('.');
                $value = 'array(...)';
            }

            $nextKey = $keysToFind->shift();
            $expectedDepth = 1;

            $depth = 0;

            foreach ($cachedParsed[$path] as $token) {
                if ($token === '[') {
                    $depth++;
                }

                if ($token === ']') {
                    $depth--;
                }

                if (!is_array($token)) {
                    continue;
                }

                $str = trim($token[1], '"\'');

                if (
                    $str === $nextKey &&
                    $depth === $expectedDepth &&
                    $token[0] === T_CONSTANT_ENCAPSED_STRING
                ) {
                    $nextKey = $keysToFind->shift();
                    $expectedDepth++;

                    if ($nextKey === null) {
                        $file = $path;
                        $line = $token[2];
                        break;
                    }
                }
            }

            if ($file) {
                break;
            }
        }
    }

    if (is_object($value)) {
        $value = get_class($value);
    }

    return [
        'name'  => $key,
        'value' => $value,
        'file'  => $file === null ? null : str_replace(base_path(DIRECTORY_SEPARATOR), '', $file),
        'line'  => $line,
    ];
}

function vsCodeUnpackDottedKey($value, $key)
{
    $arr = [$key => $value];
    $parts = explode('.', $key);
    array_pop($parts);

    while (count($parts)) {
        $arr[implode('.', $parts)] = 'array(...)';
        array_pop($parts);
    }

    return $arr;
}

echo collect(Arr::dot(config()->all()))
    ->mapWithKeys(fn ($value, $key) => vsCodeUnpackDottedKey($value, $key))
    ->map(fn ($value, $key) => vsCodeGetConfigValue($value, $key, $configPaths))
    ->filter()
    ->values()
    ->toJson();
