<?php

# proxy endpoint to support HeatmapSessionRecording tracker which sends requests to this file

define('MATOMO_PROXY_FROM_ENDPOINT', 1);

$path = 'plugins/HeatmapSessionRecording/configs.php';

# Change directory so that we can include proxy.php without breakage

$newDir = dirname(__FILE__) . '/../../';
chdir($newDir);

# Include proxy.php to enable proxying for the HeatmapSessionRecording plugin

$file = "proxy.php";
if (file_exists($file)) {
    include $file;
}

