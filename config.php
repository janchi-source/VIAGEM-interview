<?php

declare(strict_types=1);

$config = [
    'db_path' => __DIR__ . '/data/parcely.sqlite',
    'tiles_path' => __DIR__ . '/public/tiles',
    'vfr_path' => __DIR__ . '/data/vfr',
    'cuzk_base_url' => 'https://vdp.cuzk.cz/vymenny_format/soucasna/',
    'district_code' => 3604,
    'base_url' => '/',
];

$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (!is_array($localConfig)) {
        throw new RuntimeException('config.local.php must return an array.');
    }
    $config = array_replace($config, $localConfig);
}

return $config;
