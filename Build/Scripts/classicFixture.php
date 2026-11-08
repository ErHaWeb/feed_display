<?php

declare(strict_types=1);

// Never require .Build/vendor/autoload.php here: it would mask Classic failures.
$root = realpath($argv[1]);
$mode = $argv[2];
chdir($root);
$_SERVER['argv'][0] = $root . '/typo3/sysext/core/bin/typo3';
$loader = require $root . '/vendor/autoload.php';
\TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(4, 4);
if (class_exists(\SimplePie\SimplePie::class) || class_exists('SimplePie_Item')) {
    throw new RuntimeException('SimplePie must not be supplied by the Core archive.');
}
$container = \TYPO3\CMS\Core\Core\Bootstrap::init($loader);
if (\TYPO3\CMS\Core\Core\Environment::isComposerMode()) {
    throw new RuntimeException('Expected a real Classic installation.');
}
$expectedPath = realpath($root . '/typo3conf/ext/feed_display/Resources/Private/Php/ComposerVendor');
foreach ([\SimplePie\SimplePie::class, 'SimplePie_Item'] as $class) {
    $classFile = (new ReflectionClass($class))->getFileName();
    if ($expectedPath === false || !is_string($classFile) || !str_starts_with($classFile, $expectedPath . '/')) {
        throw new RuntimeException($class . ' was loaded from outside the Classic bundle.');
    }
}
if ($mode === 'probe') {
    echo 'Classic autoloading resolved both SimplePie APIs from ' . $expectedPath . PHP_EOL;
    exit(0);
}
if ($mode === 'verifyStored') {
    $pool = $container->get(\TYPO3\CMS\Core\Database\ConnectionPool::class);
    $row = $pool->getConnectionForTable('tx_feeddisplay_feed')->select(['response_data', 'revision'], 'tx_feeddisplay_feed')->fetchAssociative();
    if (!is_array($row) || !is_string($row['response_data']) || hash('sha256', $row['response_data']) !== $row['revision']) {
        throw new RuntimeException('Classic rendering did not persist a complete feed document.');
    }
    \ErHaWeb\FeedDisplay\Storage\FeedDocument::decode($row['response_data']);
    echo 'Verified the persistent Classic source document.' . PHP_EOL;
    exit(0);
}
if ($mode !== 'seed') {
    throw new InvalidArgumentException('Expected seed or probe.');
}
$pool = $container->get(\TYPO3\CMS\Core\Database\ConnectionPool::class);
$pool->getConnectionForTable('pages')->insert('pages', [
    'uid' => 1, 'pid' => 0, 'title' => 'Classic smoke', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1,
]);
$pool->getConnectionForTable('tt_content')->insert('tt_content', [
    'pid' => 1, 'CType' => 'feeddisplay_pi1', 'header' => 'Classic bundle integration', 'colPos' => 0,
]);
$siteDirectory = $root . '/typo3conf/sites/classic-smoke';
mkdir($siteDirectory, 0777, true);
file_put_contents($siteDirectory . '/config.yaml', json_encode([
    'rootPageId' => 1,
    'base' => $argv[3],
    'dependencies' => ['typo3/fluid-styled-content', 'erhaweb/feed-display'],
    'languages' => [[
        'title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/',
        'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us',
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($root . '/fileadmin/feed.xml', '<?xml version="1.0"?><rss version="2.0"><channel><title>Classic integration feed</title><link>https://example.test/</link><description>Local fixture</description><item><title>Classic bundle integration item</title><link>https://example.test/item</link><guid>classic-item</guid><description>Local content</description></item></channel></rss>');
file_put_contents($siteDirectory . '/setup.typoscript', "page = PAGE\npage.10 < styles.content.get\nconfig.no_cache = 1\nconfig.debug = 0\nplugin.tx_feeddisplay_pi1.settings.feedUrl = " . $root . "/fileadmin/feed.xml\nplugin.tx_feeddisplay_pi1.settings.cacheDuration = 0\n");
