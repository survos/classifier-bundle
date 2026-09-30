<?php

declare(strict_types=1);

// Standalone checkout uses its own vendor/; inside the mono repo, borrow the root autoloader.
if (is_file($own = dirname(__DIR__).'/vendor/autoload.php')) {
    require $own;
} else {
    $loader = require dirname(__DIR__, 3).'/vendor/autoload.php';
    $loader->addPsr4('Survos\\ClassifierBundle\\', dirname(__DIR__).'/src');
    $loader->addPsr4('Survos\\ClassifierBundle\\Tests\\', __DIR__);
}

// survos/media-topics is optional and lives next door in the mono repo; use it from there when it isn't installed.
if (!class_exists(Survos\MediaTopics\MediaTopics::class) && is_dir($lib = dirname(__DIR__, 3).'/lib/media-topics/src')) {
    spl_autoload_register(static function (string $class) use ($lib): void {
        if (str_starts_with($class, 'Survos\\MediaTopics\\')) {
            require $lib.'/'.str_replace('\\', '/', substr($class, 19)).'.php';
        }
    });
}
