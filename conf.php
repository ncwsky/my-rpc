<?php
$cfg = [
    //数据库连接信息
    'db' => array(
        'dbms' => 'mysql', //数据库 驱动类型为pdo时使用
        'server' => '192.168.0.219',    //数据库主机
        'name' => 'secure',    //数据库名称
        'user' => 'root',    //数据库用户
        'pwd' => 'root',    //数据库密码
        'port' => 3307,     // 端口
        'char' => 'utf8mb4',
    ),
    'redis' => array(
        'host' => '192.168.0.246',
        'port' => 6379,
        'password' => '123456',
        'select' => 2, //选择库
        'pconnect' => true, //长连接
    ),
    'log_level' => 1,
    //'gzip'=>true,
    // ----- rpc start -----
    'out_protocol' => true,
    'rpc_log' => false,
    'rpc_allow' => [], //允许的请求
    'rpc_auth_key' => 'yhTM2a#U3Ftj5&ZMid51O2v*zZQxy2cZXD3B', // tcp认证key|http认证Authorization  发送认证 内容:"#"+auth_key
    'rpc_allow_ip' => '', // 允许ip 优先于auth_key
    // ----- rpc end -----
];
if (is_file(__DIR__ . '/conf.local.php')) {
    $cfg = array_merge($cfg, require(__DIR__ . '/conf.local.php'));
}