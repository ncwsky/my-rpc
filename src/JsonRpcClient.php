<?php
namespace rpc;

use myphp\Log;

/**
 * Class JsonRpcClient
 * @package common\JsonRpc
 */
class JsonRpcClient
{
    use \MyMsg;

    public $maxPackageSize = 16777215;

    public static $id = 0;
    /**
     * @var string|array
     */
    public $address = '127.0.0.1:7777';
    private $_address = '';
    private $_http = false;

    /**
     * @var string 授权密码
     */
    public $password;
    /**
     * @var array http请求时使用 [name=>value, ...]
     */
    public $header = [];

    /**
     * @var float timeout to use for connection to redis. If not set the timeout set in php.ini will be used: `ini_get("default_socket_timeout")`.
     */
    public $timeout = null;
    /**
     * @var float timeout to use for redis socket when reading and writing data. If not set the php default value will be used.
     */
    public $dataTimeout = null;
    /**
     * @var integer Bitmask field which may be set to any combination of connection flags passed to [stream_socket_client()](http://php.net/manual/en/function.stream-socket-client.php).
     * Currently the select of connection flags is limited to `STREAM_CLIENT_CONNECT` (default), `STREAM_CLIENT_ASYNC_CONNECT` and `STREAM_CLIENT_PERSISTENT`.
     *
     * > Warning: `STREAM_CLIENT_PERSISTENT` will make PHP reuse connections to the same server. If you are using multiple
     * > connection objects to refer to different redis [[$database|databases]] on the same [[port]], redis commands may
     * > get executed on the wrong database. `STREAM_CLIENT_PERSISTENT` is only safe to use if you use only one database.
     * >
     * > You may still use persistent connections in this case when disambiguating ports as described
     * > in [a comment on the PHP manual](http://php.net/manual/en/function.stream-socket-client.php#105393)
     * > e.g. on the connection used for session storage, specify the port as:
     * >
     * > ```php
     * > 'port' => '6379/session'
     * > ```
     *
     * @see http://php.net/manual/en/function.stream-socket-client.php
     * @since 2.0.5
     */
    public $socketClientFlags = STREAM_CLIENT_CONNECT;
    /**
     * @var integer The number of times a command execution should be retried when a connection failure occurs.
     * This is used in [[executeCommand()]] when a [[Exception]] is thrown.
     * Defaults to 0 meaning no retries on failure.
     * @since 2.0.7
     */
    public $retries = 0;

    /**
     * @var resource redis socket connection
     */
    private $_socket = false;
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


    public function __construct($config = [])
    {
        if (!empty($config)) {
            foreach ($config as $name => $value) {
                $this->$name = $value;
            }
        }
        if (!$this->timeout) $this->timeout = ini_get('default_socket_timeout');
    }

    /**
     * Establishes a DB connection.
     * It does nothing if a DB connection has already been established.
     * @throws \Exception if connection fails
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

        if ($this->_http) return;
        if ($this->_socket !== false) {
            return;
        }
        set_error_handler(function(){});
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
                stream_set_timeout($this->_socket, $timeout = (int) $this->dataTimeout, (int) (($this->dataTimeout - $timeout) * 1000000));
            }
        } else {
            $message = 'Failed to open redis connection.';
            throw new \Exception($message.$errorDescription, $errorNumber);
        }
    }

    /**
     * Closes the currently active DB connection.
     * It does nothing if the connection is already closed.
     */
    public function close()
    {
        if ($this->_socket !== false) {
            Log::trace('Closing RPC connection: ' . $this->_address, __METHOD__);
            fclose($this->_socket);
            $this->_socket = false;
        }
    }

    /**
     * 连贯操作
     * @param string $name
     * @param array $params
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function __call($name, $params)
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
     * 连贯操作初始实例
     * @param string $class
     * @param mixed ...$params
     * @return $this|JsonRpcClient
     */
    public function instance($class, ...$params)
    {
        $instance = clone $this;
        $instance->_new = ['&' . $class => $params];
        $instance->_fluent[] = $instance->_new;
        return $instance;
    }

    //参数里使用函数
    public function sub($name, ...$params)
    {
        return ['@'.$name => $params];
    }

    /**
     * 通知
     * @param string $name
     * @param mixed ...$params
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function notify($name, ...$params)
    {
        $this->_method = $name;
        $this->_params = $params;
        return $this->send(true);
    }

    /**
     * 调用
     * @param string $name 以 /xx/xx?xx=s 类似开头的表示http请求
     * @param mixed ...$params
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function call($name, ...$params)
    {
        $this->_method = $name;
        $this->_params = $params;
        return $this->send();
    }

    public function callRaw($name, $params)
    {
        $this->_method = $name;
        $this->_params = $params;
        return $this->send();
    }

    /**
     * 批量处理 同 ->multi(), ..., ->exec()
     * @param array $methods [[name,params, notify], ...]
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function batch($methods)
    {
        $this->multi();
        foreach ($methods as $method) {
            call_user_func_array([$this, 'call'], $method);
        }
        return $this->exec();
    }

    public function multi()
    {
        $this->_multi = true;
        $this->_multiData = [];
        return null;
    }

    public function exec()
    {
        $this->open();
        $this->_multi = false;
        return $this->retryOnceSendCommand($this->_multiData);
    }

    /**
     * @param bool $notify
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    public function send($notify=false)
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
        if ($this->_params) $json['params'] = $this->_params;
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
                        throw new \Exception($e->getMessage());
                    }
                }
            }
        }
        return $this->sendCommandInternal($json);
    }

    private function retryOnceSendCommand(&$json)
    {
        try {
            return $this->sendCommandInternal($json);
        } catch (\Exception $e) {
            if ($e->getMessage() == 100) { //Failed to write to socket 重试
                Log::trace('retries:' . $e->getMessage(), 'Rpc');
                $this->close();
                $this->open();
            } else {
                throw new \Exception($e->getMessage());
            }
        }
        return $this->sendCommandInternal($json);
    }
    /**
     * Sends RAW command string to the server.
     * @param array $json
     * @param bool $response
     * @return array|bool|mixed|null
     * @throws \Exception
     */
    private function sendCommandInternal(&$json)
    {
        if ($this->_http) {
            $header = array_merge([
                'Authorization' => $this->password,
                'Content-Type' => 'application/json'
            ], $this->header);
            $data = \Http::curlSend($this->_address, 'POST', toJson($json), $this->timeout, $header);
            if ($data === false) {
                return self::errNil(\Http::$curlErr ?: '请求失败');
            }
            return \json_decode($data, true);
        }

        $command = toJson($json)."\n";
        $written = @fwrite($this->_socket, $command);
        if ($written === false) {
            throw new \Exception("Failed to write to socket.\nRpc command was: " . $command, 100);
        }
        if ($written !== ($len = strlen($command))) {
            throw new \Exception("Failed to write to socket. $written of $len bytes written.\nRpc command was: " . $command, 100);
        }
        if (!isset($json['id'])) return null; //是通知
        if (($data = fgets($this->_socket, $this->maxPackageSize)) === false) {
            throw new \Exception("Failed to read from socket.\nRpc command was: " . toJson($json));
        }
        return \json_decode($data, true);

        $ret = \json_decode($data, true);
        if (isset($ret[0])) { //批量
            return $ret;
        }
        if (isset($ret['jsonrpc'])) {
            if (!empty($ret['error'])) {
                //兼容处理
                $ret['msg'] = $ret['message'];
                $ret['data'] = $ret['data'] ?? null;
                return $ret['error']; // {code,message,data}
            } else {
                return $ret['result']; // mixed|{code:int,msg:string,data:mixed}
            }
        } else { //{code:int,msg:string,data:mixed}
            return $ret;
        }
    }
}