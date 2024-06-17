<?php
$cfg = array(
    'redis' => array(
        'host' => '192.168.0.246',
        'port' => 6379,
        'password' => '123456',
        'select' => 7, //选择库
        'pconnect'=>true, //长连接
    ),
    'log_dir' => __DIR__ . '/log/', //日志记录主目录名称
    'log_size' => 4194304,// 日志文件大小限制
    'log_level' => 2,// 日志记录等级
    // ----- rpc start -----
    'auth_key' => '', // tcp认证key  发送认证 内容:"#"+auth_key
    'allow_ip' => '', // 允许ip 优先于auth_key
    // ----- rpc end -----
);
