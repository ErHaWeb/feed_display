<?php

declare(strict_types=1);

// Standalone on purpose: verification must not load the extension's dev autoloader.
$extensionRoot = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
$lockPath = $argv[2] ?? $extensionRoot . '/Build/SimplePie/composer.lock';
$readJson = static fn(string $path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$require = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$manifest = $readJson($extensionRoot . '/composer.json');
$lock = $readJson($lockPath);
$vendorPath = 'Resources/Private/Php/ComposerVendor';
$require(($manifest['extra']['typo3/cms']['Package']['providesPackages']['simplepie/simplepie'] ?? null) === $vendorPath, 'Unexpected SimplePie providesPackages path.');
if (is_file($extensionRoot . '/Build/SimplePie/composer.json')) {
    $bundleManifest = $readJson($extensionRoot . '/Build/SimplePie/composer.json');
    foreach (['php', 'simplepie/simplepie'] as $dependency) {
        $require(($manifest['require'][$dependency] ?? null) === ($bundleManifest['require'][$dependency] ?? null), 'Root and bundle constraints differ for ' . $dependency);
    }
    $require(($bundleManifest['config']['platform']['php'] ?? null) === '8.2.0', 'Bundle must resolve for PHP 8.2.0.');
}
$expected = array_column($lock['packages'], null, 'name');
$require(isset($expected['simplepie/simplepie']), 'SimplePie is missing from the bundle lock.');
$require(($lock['packages-dev'] ?? []) === [], 'The bundle lock must not contain development dependencies.');
if (($argv[3] ?? '') === '--metadata-only') {
    exit(0);
}

$vendor = realpath($extensionRoot . '/' . $vendorPath);
$require($vendor !== false && is_file($vendor . '/autoload.php'), 'The bundle autoloader is missing.');
$installed = $readJson($vendor . '/composer/installed.json');
$require(($installed['dev'] ?? true) === false, 'The bundle was installed with development dependencies.');
$actual = array_column($installed['packages'], null, 'name');
$require(count($actual) === count($expected), 'Installed package count differs from the bundle lock.');
foreach ($expected as $name => $package) {
    $require(isset($actual[$name]), 'Missing bundled package: ' . $name);
    foreach (['version', 'source', 'dist'] as $field) {
        $require(($actual[$name][$field] ?? null) === ($package[$field] ?? null), 'Installed ' . $name . ' differs from the lock: ' . $field);
    }
}
$require(is_file($vendor . '/simplepie/simplepie/LICENSES/BSD-3-Clause.txt'), 'The SimplePie license is missing.');
$loader = require $vendor . '/autoload.php';
foreach ($loader->getClassMap() as $class => $path) {
    $resolved = realpath($path);
    $require($resolved !== false && str_starts_with($resolved, $vendor . '/'), 'Invalid bundled class path: ' . $class);
}
$require(class_exists(\SimplePie\SimplePie::class), 'The bundled autoloader cannot load SimplePie.');
$classFile = (new ReflectionClass(\SimplePie\SimplePie::class))->getFileName();
$require(is_string($classFile) && str_starts_with($classFile, $vendor . '/'), 'SimplePie was loaded from outside the bundle.');
new \SimplePie\SimplePie();
printf("Verified SimplePie %s (%s) in %s\n", $actual['simplepie/simplepie']['version'], $actual['simplepie/simplepie']['dist']['reference'], $vendor);
