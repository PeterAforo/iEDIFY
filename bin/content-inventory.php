<?php

declare(strict_types=1);

use IEdify\Modules\CMS\Services\ContentInventory;

require dirname(__DIR__) . '/vendor/autoload.php';
$report = (new ContentInventory())->inspect(dirname(__DIR__) . '/iEDIFY_Website_Content_Pack');
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
