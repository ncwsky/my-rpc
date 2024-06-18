<?php
$cfg = [
    // ----- rpc start -----
    'out_protocol' => true,
    'rpc_load' => [], //提供服务的文件、目录、匿名函数|默认是载入了myphp框架
    'rpc_log' => false,
    'rpc_allow' => [], //允许的请求
    'rpc_auth_key' => 'yhTM2a#U3Ftj5&ZMid51O2v*zZQxy2cZXD3B', // tcp认证key|http认证Authorization  发送认证 内容:"#"+auth_key
    'rpc_allow_ip' => '', // 允许ip 优先于auth_key
    // ----- rpc end -----
];
if (is_file(__DIR__ . '/conf.local.php')) {
    $cfg = array_merge($cfg, require(__DIR__ . '/conf.local.php'));
}