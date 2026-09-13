<?php

echo collect(app('view')->getFinder()->getHints())
    ->reject(fn ($_, $namespace) => strlen($namespace) === 32 && ctype_xdigit($namespace))
    ->map(fn ($paths) => collect($paths)
        ->map(fn ($path) => [
            'path'     => LspHelper::relativePath($path),
            'isVendor' => LspHelper::isVendor($path),
        ])
        ->values())
    ->toJson();
