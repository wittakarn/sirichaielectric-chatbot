<?php

ini_set('error_log', dirname(__FILE__) . '/logs.log');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/SirichaiLineWebhook.php';

(new SirichaiLineWebhook())->run();
