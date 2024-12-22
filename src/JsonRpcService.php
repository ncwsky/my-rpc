<?php

declare(strict_types=1);

namespace rpc;

use myphp\Helper;
use myphp\Log;
use myphp\Response;

class JsonRpcService
{
    use \MyMsg;

    //标准jsonRpc、普通jsonRpc[]
    /**
     * @var array 设定允许的操作
     */
    public static $allow = [];

    //简单认证处理 优先IP认证
    public static $allowIp = '';
    public static $authKey = ''; //简单密码验证

    public static $outProtocol = true; //是否输出协议标识
    public static $log = false;
    protected static $instances = [];

    public static function init()
    {
        self::$outProtocol = GetC('out_protocol');
        self::$log = GetC('rpc_log');
        self::$authKey = GetC('rpc_auth_key');
        self::$allowIp = GetC('rpc_allow_ip');
        self::$allow = GetC('rpc_allow');

        self::load(GetC('rpc_load', []));
    }

    // 循环load配置文件或匿名函数 ['file1','file2',...,function(){},...] || function(){};
    public static function load($rpc_load)
    {
        foreach ((array)$rpc_load as $load) {
            if (is_string($load)) {
                if (is_file($load)) {
                    include $load;
                } elseif (is_dir($load)) {
                    \myphp::class_dir($load);
                }
            } elseif ($load instanceof \Closure) {
                call_user_func($load);
            }
        }
    }

    private static function _checkAllow($c, $a)
    {
        return !in_array($c, self::$allow) && !in_array($c . '/' . $a, self::$allow);
    }

    private static function _error($code, $message, $id = null)
    {
        if (self::$outProtocol) {
            ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message], 'id' => $id];
        }
        return ['error' => ['code' => $code, 'message' => $message], 'id' => $id];
    }

    private static function _result(&$result, $id)
    {
        if (self::$outProtocol) {
            return ['jsonrpc' => '2.0', 'result' => $result, 'id' => $id];
        }
        return ['result' => $result, 'id' => $id];
    }

    public static function run($json, $con = null, $fd = 0)
    {
        if (self::$log) {
            Log::write($json, 'req');
        }
        //认证处理
        $authRet = self::auth($con, $fd, $json);
        if (self::$log) {
            Log::write($authRet, 'srv-auth');
        }
        if (false === $authRet) {
            self::toClose($con, $fd, self::err());
            return null;
        }
        if ($authRet === null) {
            return null;
        }

        $request = json_decode($json, true);
        if ($request === null) {
            return self::_error(-32700, 'Parse error');
        }
        if (!is_array($request) || empty($request)) {
            return self::_error(-32600, 'Invalid Request');
        }

        \set_error_handler(function ($code, $msg, $file, $line) use ($json) {
            if (!self::$log) {
                Log::write($json, 'req');
            }
            Log::WARN($file . ':' . $line . ', err:' . $msg);
        });
        $result = [];
        if (isset($request[0])) { //批量 [{}, ...]
            foreach ($request as $item) {
                $ret = self::handle($item);
                if ($ret !== null) { //不是通知
                    $result[] = $ret;
                }
            }
        } else {
            $result = self::handle($request);
        }
        if (self::$log) {
            Log::write(substr(toJson($result), 0, 1024), 'res');
        }
        \restore_error_handler();

        return $result;
    }

    public static function handle(&$request)
    {
        if (empty($request['method']) || !is_string($request['method'])) {
            return self::_error(-32601, 'Method not found');
        }

        try {
            $result = $request['method'][0] == '/' ? self::url($request) : self::call($request);
        } catch (\Exception $e) {
            return self::_error($e->getCode() ?: -32603, $e->getMessage(), $request['id'] ?? null);
        } catch (\Error $e) {
            return self::_error(-32603, 'Internal error: ' . $e->getMessage(), $request['id'] ?? null);
        }

        if (isset($request['id'])) {
            return self::_result($result, $request['id']);
        }
        return null;
    }

    public static function url(&$request)
    {
        $_env = \myphp::$env;
        #$_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = (string)\parse_url($request['method'], PHP_URL_PATH);
        $_SERVER["REQUEST_URI"] = $request['method'];
        $_SERVER['QUERY_STRING'] = (string)\parse_url($request['method'], PHP_URL_QUERY);
        $_GET = [];
        $_POST = [];
        if ($_SERVER['QUERY_STRING'] !== '') { //对http请求GET参数解析
            \parse_str($_SERVER['QUERY_STRING'], $_GET);
        }
        if (isset($request['params']) && $request['params']) { //对http请求post|post raw兼容处理
            if (is_array($request['params'])) {
                $_POST = $request['params'];
                Log::write($_POST, 'post');
            } elseif (is_string($request['params'])) {
                \myphp::req()->setRawBody($request['params']);
                $chr = $request['params'][0]; //取第一个字符用于简单判断是否为json
                if ($chr != '[' && $chr != '{') {
                    \parse_str($request['params'], $_POST);
                }
            }
        }
        $_REQUEST = array_merge($_GET, $_POST);

        \myphp::Analysis(false);
        try {
            $res = \myphp::handle();
            if ($res === null) {
                if (\myphp::res()->getStatusCode() == 200) {
                    $result = \myphp::res()->getBody();
                } else {
                    throw new \Exception(\myphp::res()->getReasonPhrase(), \myphp::res()->getStatusCode());
                }
            } else {
                $result = $res instanceof Response ? $res->getBody() : $res;
            }
            return $result;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage(), $e->getCode() ?: -32603);
        } catch (\Error $e) {
            throw new \Exception($e->getMessage(), $e->getCode() ?: -32603);
        } finally {
            //重置数据
            \myphp::req()->clear();
            \myphp::res()->clear();
            \myphp::$env = $_env;
        }
    }

    public static function call(&$request)
    {
        $request['params'] = isset($request['params']) ? (array)$request['params'] : [];
        if ($request['method'] == '->') { //连贯操作
            if (empty($request['params'])) {
                throw new \Exception('Invalid params', -32602);
            }
            $result = array_reduce($request['params'], function ($carry, $item) {
                $method = key($item);
                //对参数里有函数调用递归处理
                $params = self::paramsRecursive((array)$item[$method]);

                if (isset($carry)) {
                    return call_user_func_array([$carry, $method], $params);
                }

                //连贯开始部分
                if (substr($method, 0, 1) == '&') { //是创建新实例
                    $className = substr($method, 1);
                    $key = $className;
                    if ($params) {
                        $key .= toJson($params);
                        //对新实例缓存
                        if (!isset(self::$instances[$key])) {
                            $class = new \ReflectionClass($className);
                            self::$instances[$key] = $class->newInstanceArgs($params);
                        }
                    } else {
                        //对新实例缓存
                        if (!isset(self::$instances[$key])) {
                            self::$instances[$key] = new $className();
                        }
                    }
                    return self::$instances[$key];
                }
                return call_user_func_array($method, $params);
            });
        } elseif (strpos($request['method'], '.')) { //对象.方法
            [$className, $method] = explode('.', $request['method'], 2);
            $key = $className;
            //对新实例缓存
            if (!isset(self::$instances[$key])) {
                if (!class_exists($className)) {
                    throw new \Exception('Class not found', -32601);
                }
                if (!method_exists($className, $method)) {
                    throw new \Exception('Method not found', -32601);
                }
                self::$instances[$key] = new $className();
            }
            $result = call_user_func_array([self::$instances[$key], $method], self::paramsRecursive($request['params']));
        } elseif (strpos($request['method'], '::')) {//对象::静态方法
            [$className, $method] = explode('::', $request['method'], 2);
            if (!class_exists($className)) {
                throw new \Exception('Class not found', -32601);
            }
            if (!method_exists($className, $method)) {
                throw new \Exception('Method not found', -32601);
            }
            $result = call_user_func_array($request['method'], self::paramsRecursive($request['params']));
        } else { //普通函数
            if (!function_exists($request['method'])) {
                throw new \Exception('Method not found', -32601);
            }
            $result = call_user_func_array($request['method'], self::paramsRecursive($request['params']));
        }
        return $result;
    }

    /**
     * 参数递归处理
     * @param array $params
     * @return mixed
     * @throws \Exception
     */
    public static function paramsRecursive($params)
    {
        if (!$params) {
            return $params;
        }
        foreach ($params as $key => $param) {
            if (is_array($param)) {
                $name = key($param);
                if (is_int($name)) {
                    continue;
                }
                if (substr($name, 0, 1) == '@') { //调用函数处理
                    //todo 使用直接调用方式流程
                    $request = ['method' => substr($name, 1), 'params' => self::paramsRecursive($param[$name])];
                    $params[$key] = self::call($request);
                    #$params[$key] = call_user_func_array(substr($name, 1), self::paramsRecursive($param[$name]));
                }
            }
        }
        return $params;
    }

    public static function remoteIp($con, $fd)
    {
        if (\SrvBase::$instance->isWorkerMan) {
            return $con->getRemoteIp();
        }

        return $con->getClientInfo($fd)['remote_ip'];
    }

    public static function authHttp()
    {
        //优先ip
        if (static::$allowIp) {
            return \myphp\Helper::allowIp(Helper::getIp(), static::$allowIp);
        }
        //认证key
        if (!static::$authKey) {
            return true;
        }
        return static::$authKey == \myphp::req()->getHeader('Authorization');
    }
    /**
     * tcp 认证
     * @param \Workerman\Connection\TcpConnection|\swoole_server $con
     * @param int $fd
     * @param string $recv
     * @return bool|null
     */
    public static function auth($con, $fd, $recv = '')
    {
        //优先ip
        if (static::$allowIp) {
            return $recv === null || \myphp\Helper::allowIp(static::remoteIp($con, $fd), static::$allowIp);
        }
        //认证key
        if (!static::$authKey) {
            return true;
        }

        if (!isset(\SrvBase::$instance->auth)) {
            \SrvBase::$instance->auth = [];
        }

        //连接断开清除
        if ($recv === null) {
            unset(\SrvBase::$instance->auth[$fd]);
            \SrvBase::$isConsole && \SrvBase::safeEcho('clear auth '.$fd);
            return true;
        }

        if ($recv) {
            if (isset(\SrvBase::$instance->auth[$fd]) && \SrvBase::$instance->auth[$fd] === true) {
                return true;
            }
            \SrvBase::$instance->server->clearTimer(\SrvBase::$instance->auth[$fd]);
            if ($recv == static::$authKey) { //通过认证
                \SrvBase::$instance->auth[$fd] = true;
            } else {
                $errJson = \json_encode(self::_error(-32403, 'auth fail'));
                return static::err($errJson);
            }
            return null;
        }

        //创建定时认证
        if (!isset(\SrvBase::$instance->auth[$fd])) {
            \SrvBase::$isConsole && \SrvBase::safeEcho('auth timer ' . $fd . PHP_EOL);
            \SrvBase::$instance->auth[$fd] = \SrvBase::$instance->server->after(1000, function () use ($con, $fd) {
                unset(\SrvBase::$instance->auth[$fd]);
                \SrvBase::$isConsole && \SrvBase::safeEcho('auth timeout to close ' . $fd . PHP_EOL);
                if (\SrvBase::$instance->isWorkerMan) {
                    $con->close();
                } else {
                    $con->close($fd);
                }
            });
        }
        return true;
    }
    /**
     * @param \Workerman\Connection\TcpConnection|\swoole_server $con
     * @param int $fd
     * @param string $msg
     */
    public static function toClose($con, $fd = 0, $msg = null)
    {
        if (\SrvBase::$instance->isWorkerMan) {
            $con->close($msg);
        } else {
            if ($msg) {
                $con->send($fd, $msg);
            }
            $con->close($fd);
        }
    }
}
