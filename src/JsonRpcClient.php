<?php

declare(strict_types=1);

namespace rpc;

use myphp\Log;

/**
 * JsonRpcClient todo 异步调用
 */
class JsonRpcClient
{
    public $maxPackageSize = 16777215; //16M

    public static $id = 0;
    /**
     * @var string|array
     */
    public $address = '127.0.0.1:7777';
    private $_address = '';
    private $_http = false;

    /**
     * @var int 错误码
     */
    private $code = 0;
    /**
     * @var string 错误信息
     */
    private $message = '';
    /**
     * @var string 授权key 如果服务端有配置IP验证时此key无效
     */
    public $auth_key;
    /**
     * @var array http请求时使用 [name=>value, ...]
     */
    public $header = [];

    /**
     * @var callable|null 调用后执行 传入结果参数
     */
    public $call_after = null;

    /**
     * @var float 连接超时 未设置使用: `ini_get("default_socket_timeout")`.
     */
    public $timeout = null;
    /**
     * @var float 数据超时
     */
    public $dataTimeout = null;
    /**
     * @var int 连接标识
     */
    public $socketClientFlags = STREAM_CLIENT_CONNECT;
    /**
     * @var int 失败重试次数 默认0不重试
     */
    public $retries = 0;

    /**
     * @var resource rpc connection
     */
    private $_socket;
    private $_method = '';
    private $_params = [];
    private $_fluent = [];
    /**
     * @var array 批量处理
     */
    private $_multiData = [];
    private $_multi = false;

    /**
     * @var array 新实例
     */
    private $_new = [];

    public function __construct(array $config = [])
    {
        if (!empty($config)) {
            foreach ($config as $name => $value) {
                $this->$name = $value;
            }
        }
        if (!$this->timeout) {
            $this->timeout = (float)ini_get('default_socket_timeout');
        }
    }

    /**
     * 打开连接
     * @return void
     * @throws \Exception
     */
    public function open()
    {
        if ($this->_address === '') { //取请求地址
            if (is_array($this->address)) {
                $this->_address = $this->address[array_rand($this->address)];
            } else {
                $this->_address = $this->address;
            }
            if (substr($this->_address, 0, 4) == 'http') {
                $this->_http = true;
            } else {
                $this->_http = false;
            }
        }

        if ($this->_http) {
            return;
        }
        if (is_resource($this->_socket)) {
            return;
        }
        set_error_handler(function () {
            return true;
        });
        $this->_socket = @stream_socket_client(
            'tcp://' . $this->_address,
            $errorNumber,
            $errorDescription,
            $this->timeout,
            $this->socketClientFlags
        );
        restore_error_handler();
        if ($this->_socket) {
            if ($this->dataTimeout !== null) {
                $timeout = (int)$this->dataTimeout; //秒部分
                $microseconds = (int) (($this->dataTimeout - $timeout) * 1000000); //微秒部分
                stream_set_timeout($this->_socket, $timeout, $microseconds);
            }
            if ($this->auth_key) {
                $written = @fwrite($this->_socket, $this->auth_key . "\n");
                if ($written === false) {
                    throw new \Exception("Failed to write to socket.\nRpc command was: auth");
                }
                $data = fgets($this->_socket, $this->maxPackageSize);
                if ($data) {
                    throw new \Exception($data);
                }
            }
        } else {
            $message = 'Failed to open redis connection.';
            throw new \Exception($message.$errorDescription, $errorNumber);
        }
    }

    /**
     * 关闭连接
     * @return void
     */
    public function close()
    {
        if (is_resource($this->_socket)) {
            Log::trace('Closing RPC connection: ' . $this->_address, __METHOD__);
            fclose($this->_socket);
        }
    }

    /**
     * 连贯操作(直接指定调用函数、方法、静态方法)
     * @param string $name
     * @param mixed ...$params
     * @return $this
     */
    public function fluent(string $name, ...$params): JsonRpcClient
    {
        $this->_fluent[] = [$name => $params];
        return $this;
    }

    /**
     * 连贯操作
     * @param string $name
     * @param array $params
     * @return $this
     */
    public function __call(string $name, array $params)
    {
        $this->_fluent[] = [$name => $params];
        return $this;
    }

    public function __clone()
    {
        $this->_fluent = [];
        $this->_multiData = [];
        $this->_multi = false;
        $this->_new = [];
    }

    /**
     * 类初始实例
     * @param string $class
     * @param mixed ...$params
     * @return JsonRpcClient
     */
    public function instance(string $class, ...$params): JsonRpcClient
    {
        $instance = clone $this;
        $instance->_new = ['&' . $class => $params];
        $instance->_fluent[] = $instance->_new;
        return $instance;
    }

    /**
     * 调用(call)里的参数使用函数
     * @param string $name
     * @param ...$params
     * @return array[]
     */
    public function sub(string $name, ...$params)
    {
        return ['@'.$name => $params];
    }

    /**
     * 通知（无id字段）用于不关心结果的场景，例如日志记录等
     * @param string $name
     * @param mixed ...$params
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function notify(string $name, ...$params)
    {
        $this->_method = $name;
        $this->_params = $params;
        return $this->send(true);
    }

    /**
     * 调用-可变数量的参数
     * @param string $name 以 /xx/xx?xx=s 类似开头的表示http请求
     * @param mixed ...$params
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function call(string $name, ...$params)
    {
        $this->_method = $name;
        $this->_params = $params;
        return $this->send();
    }

    /**
     * 调用-区别call参数只有一项，多个参数以数组传入
     * @param string $name
     * @param mixed $params
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function callRaw(string $name, $params)
    {
        $this->_method = $name;
        $this->_params = $params;
        return $this->send();
    }

    /**
     * 批量处理 同 ->multi(), ..., ->exec()
     * @param array $methods [[name,params], ...]
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function batch(array $methods)
    {
        $this->multi();
        foreach ($methods as $method) {
            call_user_func_array([$this, 'call'], $method);
        }
        return $this->exec();
    }

    /**
     * 批量-初始
     * @return null
     */
    public function multi()
    {
        $this->_multi = true;
        $this->_multiData = [];
        return null;
    }

    /**
     * 批量-执行
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function exec()
    {
        $this->open();
        $this->_multi = false;
        try {
            return $this->sendCommandInternal($this->_multiData, true);
        } catch (\Exception $e) {
            if ($e->getMessage() == 100) { //Failed to write to socket 重试
                Log::trace('retries:' . $e->getMessage(), 'Rpc');
                $this->close();
                $this->open();
            } else {
                throw new \Exception($e->getMessage());
            }
        }
        return $this->sendCommandInternal($this->_multiData, true);
    }

    /**
     * @param bool $notify 是否仅通知不回数据
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function send(bool $notify = false)
    {
        $this->open();
        if ($this->_fluent) { //连贯处理
            if (count($this->_fluent) == 1) {
                $this->_method = key($this->_fluent[0]);
                $this->_params = $this->_fluent[0][$this->_method];
            } else {
                $this->_method = '->'; //连贯标识
                $this->_params = $this->_fluent;
            }
            $this->_fluent = $this->_new ? [$this->_new] : [];
        }

        $json = [
            'jsonrpc' => '2.0',
            'method' => $this->_method
        ];
        if ($this->_params) {
            $json['params'] = $this->_params;
        }
        if (!$notify) {
            self::$id++;
            $json['id'] = self::$id;
        }
        $this->_method = '';
        $this->_params = [];

        if ($this->_multi) {
            $this->_multiData[] = $json;
            return null;
        }

        if ($this->retries > 0) {
            $tries = $this->retries;
            while ($tries-- > 0) {
                try {
                    return $this->sendCommandInternal($json);
                } catch (\Exception $e) {
                    if ($e->getMessage() == 100) { //Failed to write to socket 重试
                        Log::trace('retries' . $tries . ', ' . $json['method'] . ', ' . $e->getMessage(), 'Rpc');
                        // backup retries, fail on commands that fail inside here
                        $retries = $this->retries;
                        $this->retries = 0; //防止在close里调用send
                        $this->close();
                        $this->open();
                        $this->retries = $retries;
                    } else {
                        return $this->error(-32603, $e->getMessage(), $json['id'] ?? null);
                        //throw new \Exception($e->getMessage());
                    }
                }
            }
        }
        return $this->sendCommandInternal($json);
    }

    /**
     * 发送原始json数据到服务端
     * @param array $json
     * @param bool $multi
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    private function sendCommandInternal(array &$json, bool $multi = false)
    {
        $this->code = 0;
        $this->message = '';
        if ($this->_http) {
            $header = array_merge([
                'Authorization' => $this->auth_key,
                'Content-Type' => 'application/json'
            ], $this->header);
            $data = \Http::curlSend($this->_address, 'POST', toJson($json), (int)$this->timeout, $header);
            if ($data === false) {
                return $this->error(-32001, \Http::$curlErr ?: '请求失败', $json['id'] ?? null);
            }
        } else {
            $command = toJson($json)."\n";
            $written = @fwrite($this->_socket, $command);
            if ($written === false) {
                return $this->error(-32001, "Failed to read to write.\nRpc command was: " . $command, $json['id'] ?? null);
            }
            if (!$multi && !isset($json['id'])) { //是通知
                return null;
            }
            if (($data = fgets($this->_socket, $this->maxPackageSize)) === false) {
                return $this->error(-32002, "Failed to read to socket.\nRpc command was: " . $command, $json['id'] ?? null);
            }
        }
        $jsonRet = \json_decode($data, true); //-32403 没有权限
        /*if (isset($jsonRet['error']) && $jsonRet['error']['code'] == -32403) {
            throw new \Exception(rtrim($data));
        }*/
        if (isset($jsonRet['error'])) {
            $this->code = $jsonRet['error']['code'];
            $this->message = $jsonRet['error']['message'];
        }
        // 调用后执行
        if ($this->call_after) {
            return call_user_func($this->call_after, $jsonRet);
        }
        return $jsonRet;
        /*
        if (isset($jsonRet[0])) { //批量
            return $jsonRet;
        }
        if (!empty($jsonRet['error'])) {
            $jsonRet['error']['msg'] = $jsonRet['error']['message']; //兼容处理
            if (!isset($jsonRet['error']['data'])) {
                $jsonRet['error']['data'] = null;
            }
            return $jsonRet['error']; // {code,message,data}
        } else {
            return $jsonRet?$jsonRet['result']:null; // mixed|{code:int,msg:string,data:mixed}
        }*/
    }

    private function error(int $code, string $message, $id = null, $data = null): array
    {
        $this->code = $code;
        $this->message = $message;
        return ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message, 'data' => $data], 'id' => $id];
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
