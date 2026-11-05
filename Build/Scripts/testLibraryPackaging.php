<?php

declare(strict_types=1);

// Exercise packaging failures without changing the checkout or its runtime bundle.
$root = dirname(__DIR__, 2);
$original = $argv[1];
$temporary = sys_get_temp_dir() . '/feed-display-packaging-test-' . bin2hex(random_bytes(8));
mkdir($temporary);
$run = static function (array $command, bool $expectSuccess): void {
    $process = proc_open($command, [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if (($status === 0) !== $expectSuccess) {
        throw new RuntimeException('Unexpected result: ' . implode(' ', $command) . PHP_EOL . $output);
    }
};
try {
    foreach (['autoload.php', 'simplepie/simplepie/LICENSES/BSD-3-Clause.txt', 'simplepie/simplepie/src/SimplePie.php'] as $missing) {
        $archive = $temporary . '/broken.zip';
        copy($original, $archive);
        $zip = new ZipArchive();
        $zip->open($archive);
        if (!$zip->deleteName('Resources/Private/Php/ComposerVendor/' . $missing)) {
            throw new RuntimeException('Missing test archive entry: ' . $missing);
        }
        $zip->close();
        $run([PHP_BINARY, $root . '/Build/Scripts/verifyTerArtefact.php', $archive, $root], false);
    }
    $zip = new ZipArchive();
    copy($original, $temporary . '/phar.zip');
    $zip->open($temporary . '/phar.zip');
    $zip->addFromString('Libraries/unexpected.phar', 'unexpected');
    $zip->close();
    $run([PHP_BINARY, $root . '/Build/Scripts/verifyTerArtefact.php', $temporary . '/phar.zip', $root], false);

    mkdir($temporary . '/Build/SimplePie', 0777, true);
    mkdir($temporary . '/Build/Scripts');
    copy($root . '/composer.json', $temporary . '/composer.json');
    foreach (['buildLibraries.sh', 'verifyLibraries.php'] as $script) {
        copy($root . '/Build/Scripts/' . $script, $temporary . '/Build/Scripts/' . $script);
    }
    copy($root . '/Build/SimplePie/composer.json', $temporary . '/Build/SimplePie/composer.json');
    $run(['bash', $temporary . '/Build/Scripts/buildLibraries.sh'], false);
    copy($root . '/Build/SimplePie/composer.lock', $temporary . '/Build/SimplePie/composer.lock');
    $manifest = json_decode(file_get_contents($temporary . '/Build/SimplePie/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['require']['simplepie/simplepie'] = '^999.0';
    file_put_contents($temporary . '/Build/SimplePie/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $run(['bash', $temporary . '/Build/Scripts/buildLibraries.sh'], false);

    // An otherwise valid archive must also fail against a different release lock.
    $lock = json_decode(file_get_contents($temporary . '/Build/SimplePie/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $lock['packages'][0]['dist']['reference'] = 'wrong-release-reference';
    file_put_contents($temporary . '/Build/SimplePie/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    $run([PHP_BINARY, $root . '/Build/Scripts/verifyTerArtefact.php', $original, $temporary], false);
    echo "Verified missing/stale locks, incomplete archives, unexpected PHARs and mismatched release references.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
