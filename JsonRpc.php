#!/usr/bin/env php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');// 有些环境关闭了错误显示

if (realpath(dirname($_SERVER['SCRIPT_FILENAME'])) != __DIR__ && !defined('RUN_DIR')) {
    define('RUN_DIR', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
    //$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行 不设置此项使用相对路径运行时 会加载了不相应的引入文件
}

defined('RUN_DIR') || define('RUN_DIR', __DIR__);
if (!defined('VENDOR_DIR')) {
    if (is_dir(__DIR__ . '/vendor')) {
        define('VENDOR_DIR', __DIR__ . '/vendor');
    } elseif (is_dir(__DIR__ . '/../vendor')) {
        define('VENDOR_DIR', __DIR__ . '/../vendor');
    } elseif (is_dir(__DIR__ . '/../../../vendor')) {
        define('VENDOR_DIR', __DIR__ . '/../../../vendor');
    }
}

defined('MY_PHP_DIR') || define('MY_PHP_DIR', VENDOR_DIR . '/myphps/myphp');
//defined('MY_PHP_SRV_DIR') || define('MY_PHP_SRV_DIR', VENDOR_DIR . '/myphps/my-php-srv');

defined('RPC_NAME') || define('RPC_NAME', 'MyMQ');
defined('RPC_LISTEN') || define('RPC_LISTEN', '0.0.0.0');
defined('RPC_PORT') || define('RPC_PORT', 7777);
defined('IS_SWOOLE') || define('IS_SWOOLE', 0);
defined('STOP_TIMEOUT') || define('STOP_TIMEOUT', 10); //进程结束超时时间 秒
defined('MAX_INPUT_SIZE') || define('MAX_INPUT_SIZE', 65536); //接收包限制大小64k

require VENDOR_DIR . '/autoload.php';
require MY_PHP_DIR . '/GetOpt.php';
defined('MY_PHP_SRV_DIR') && require MY_PHP_SRV_DIR . '/Load.php';

//解析命令参数
GetOpt::parse('sp:l:', ['help', 'swoole', 'port:', 'listen:']);
//处理命令参数
$isSwoole = GetOpt::has('s', 'swoole') || IS_SWOOLE;
$port = (int)GetOpt::val('p', 'port', RPC_PORT);
$listen = GetOpt::val('l', 'listen', RPC_LISTEN);
//自动检测
if (!$isSwoole && !SrvBase::workermanCheck() && defined('SWOOLE_VERSION')) {
    $isSwoole = true;
}

if (GetOpt::has('h', 'help')) {
    echo 'Usage: php JsonRpc.php OPTION [restart|reload|stop]
   or: JsonRpc.php OPTION [restart|reload|stop]

   --help
   -l --listen    监听地址 默认 0.0.0.0
   -p --port      tcp 端口
   -s --swoole    swolle运行', PHP_EOL;
    exit(0);
}
if(!is_file(RUN_DIR . '/conf.php')){
    echo RUN_DIR . '/conf.php does not exist';
    exit(0);
}

$conf = [
    'name' => RPC_NAME, //服务名
    'ip' => $listen,
    'port' => $port,
    'type' => 'tcp',
    'setting' => [ //swooleSrv有兼容处理
        'count' => 1, //单进程模式
        /*--- workerman ---*/
        'protocol' => '\\Workerman\\Protocols\\Text',
        'stdoutFile' => RUN_DIR . '/log.log', //终端输出 workerman
        /*--- workerman end ---*/
        'pidFile' => RUN_DIR . '/mq.pid',  //pid_file
        'logFile' => RUN_DIR . '/log.log', //日志文件 log_file
        /*--- swoole ---*/
        'open_eof_check' => true,   //打开EOF检测
        'package_eof' => "\n", //设置EOF
        'package_max_length' => MAX_INPUT_SIZE, //64k
        'max_wait_time' => STOP_TIMEOUT, //设置 Worker 进程收到停止服务通知后最大等待时间【默认值：3】
        /*--- swoole end ---*/
    ],
    'event' => [
        'onConnect' => function ($con, $fd = 0) use ($isSwoole) {
            if (!$isSwoole) {
                $fd = $con->id;
            }
            \SrvBase::$isConsole && SrvBase::safeEcho('onConnect ' . $fd . PHP_EOL);

            if (false===\rpc\JsonRpcService::auth($con, $fd)) { //启动验证
                \rpc\JsonRpcService::toClose($con, $fd);
            }
        },
        'onClose' => function ($con, $fd = 0) use ($isSwoole) {
            if (!$isSwoole) {
                $fd = $con->id;
            }
            \rpc\JsonRpcService::auth($con, $fd, null); //清除验证
            \SrvBase::$isConsole && SrvBase::safeEcho(date("Y-m-d H:i:s ") . microtime(true) . ' onClose ' . $fd . PHP_EOL);
        },
        'onReceive' => function (swoole_server $server, int $fd, int $reactor_id, string $data) { //swoole tcp
            $ret = \rpc\JsonRpcService::run($data, $server, $fd);
            if ($ret !== null) $server->send($fd, toJson($ret));
        },
        'onMessage' => function (\Workerman\Connection\TcpConnection $connection, $data) { //workerman
            $ret = \rpc\JsonRpcService::run($data, $connection, $connection->id);
            if ($ret !== null) $connection->send(toJson($ret));
        }
    ],
    // 进程内加载的文件
    'worker_load' => [
        RUN_DIR . '/conf.php',
        MY_PHP_DIR . '/base.php',
        function () {
            \rpc\JsonRpcService::init();
        }
    ],
];

if ($isSwoole) {
    $srv = new SwooleSrv($conf);
} else {
    // 设置每个连接接收的数据包最大为64K
    \Workerman\Connection\TcpConnection::$defaultMaxPackageSize = MAX_INPUT_SIZE;
    $srv = new WorkerManSrv($conf);
    Worker2::$stopTimeout = STOP_TIMEOUT; //强制进程结束等待时间
}
$srv->run($argv);