#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Translation\Command\XliffLintCommand;
use Symfony\Component\Yaml\Command\LintCommand;

require dirname(__DIR__, 2) . '/.Build/vendor/autoload.php';

$application = new Application();
$application->add(new XliffLintCommand(null, null, null, false));
$application->add(new LintCommand());

exit($application->run());
