#!/usr/bin/env php
<?php
define('RUN_DIR', __DIR__);
define('VENDOR_DIR', __DIR__ . '/vendor');
//define('MY_PHP_DIR', __DIR__ . '/vendor/myphps/myphp');
//define('MY_PHP_SRV_DIR', __DIR__ . '/vendor/myphps/my-php-srv');
define('RPC_NAME', 'jsonRpc');
define('RPC_LISTEN', '0.0.0.0');
define('RPC_PORT', 7777);
define('IS_SWOOLE', 0);

require __DIR__. '/vendor/myphps/my-rpc/JsonRpc.php';