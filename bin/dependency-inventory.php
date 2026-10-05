<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$cutoff = new DateTimeImmutable('-7 days');
$recent = [];
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    $date = new DateTimeImmutable($package['time']);
    $line = implode(' | ', [$package['name'], $package['version'], implode(', ', $package['license'] ?? []), $date->format('Y-m-d')]);
    fwrite(STDOUT, $line . PHP_EOL);
    if ($date > $cutoff) {
        $recent[] = $package['name'];
    }
}
if ($recent !== []) {
    fwrite(STDERR, 'Review releases newer than seven days: ' . implode(', ', $recent) . PHP_EOL);
    exit(1);
}
