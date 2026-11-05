<?php

declare(strict_types=1);

$zip = new ZipArchive();
if ($zip->open($argv[1]) !== true) {
    throw new RuntimeException('Cannot open TER artefact.');
}
$sourceRoot = realpath($argv[2] ?? getcwd());
$manifest = json_decode($zip->getFromName('composer.json'), true, 512, JSON_THROW_ON_ERROR);
$bundlePath = $manifest['extra']['typo3/cms']['Package']['providesPackages']['simplepie/simplepie'] ?? null;
$isBundle = $bundlePath === 'Resources/Private/Php/ComposerVendor';
$temporary = sys_get_temp_dir() . '/feed-display-artefact-' . bin2hex(random_bytes(8));
mkdir($temporary);
try {
    for ($index = 0; $index < $zip->numFiles; ++$index) {
        $entry = $zip->getNameIndex($index);
        if (str_starts_with($entry, '/') || str_contains($entry, '\\') || in_array('..', explode('/', $entry), true)) {
            throw new RuntimeException('Unsafe archive entry: ' . $entry);
        }
        if ($isBundle && str_ends_with(strtolower($entry), '.phar')) {
            throw new RuntimeException('The new TER package must not contain a PHAR.');
        }
    }
    if (!$zip->extractTo($temporary)) {
        throw new RuntimeException('Cannot extract TER artefact.');
    }
    $zip->close();
    if ($isBundle) {
        $lock = $sourceRoot . '/Build/SimplePie/composer.lock';
        if (!is_file($lock)) {
            throw new RuntimeException('The tagged release bundle lock is missing.');
        }
        $command = [PHP_BINARY, __DIR__ . '/verifyLibraries.php', $temporary, $lock];
    } else {
        // Old tags keep their original PHAR; do not rebuild them with today's lock.
        $phar = $temporary . '/Libraries/simplepie-simplepie.phar';
        if (!is_file($phar)) {
            throw new RuntimeException('The legacy SimplePie PHAR is missing.');
        }
        $command = [PHP_BINARY, '-r', 'require $argv[1]; if (!class_exists("SimplePie\\\\SimplePie")) { exit(1); }', 'phar://' . $phar . '/vendor/autoload.php'];
    }
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('The isolated TER runtime check failed.');
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
