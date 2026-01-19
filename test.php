<?php

declare(strict_types=1);
require __DIR__ . "/vendor/autoload.php";
require __DIR__ . "/conf.php";
require __DIR__ . "/vendor/myphps/myphp/base.php";

function eJson($v)
{
    echo toJson($v),PHP_EOL;
}

$jsonRpc = new \rpc\JsonRpcClient([
    'address' => ['10.0.0.219:7777'],
    'password'=>'123456'
]);
/* //http模式下使用
$jsonRpc = new \rpc\JsonRpcClient([
    'address'=>['http://192.168.0.219:8049/json-rpc']
]);
var_dump($jsonRpc->multi());
var_dump($jsonRpc->call('/index/time'));
var_dump($jsonRpc->notify('/index/time'));
eJson($jsonRpc->call('orderSN', 3, 2));
var_dump($jsonRpc->call('/index/barInfo?bar_id=1'));
var_dump($jsonRpc->callRaw('/index/netbar', 'id=108&sign=f09568fe32c9f1f79512867c26256b2c'));
echo toJson($jsonRpc->exec()),PHP_EOL;
*/
eJson($jsonRpc->multi());
eJson($jsonRpc->call('orderSN', 3, 2));
eJson($jsonRpc->callRaw('\common\weixin\RedisQRCode.create', [100, ['key' => bin2hex(random_bytes(6))]]));
eJson($jsonRpc->exec());
$ret = $jsonRpc->call('base64_encode', $jsonRpc->sub('\myphp\Helper::rc4', $jsonRpc->sub('urlencode', $jsonRpc->sub('toJson', ['我是外星人'])), '123456'));
eJson($ret);

$ret = $jsonRpc->call('json_decode', $jsonRpc->sub('urldecode', $jsonRpc->sub('\myphp\Helper::rc4', $jsonRpc->sub('base64_decode', $ret['result']), '123456')));
eJson($ret);

#var_dump($jsonRpc->call('\myphp\Helper::rc4',[$ret['result'], '123456']));
eJson($jsonRpc->call('redis'));

$des = $jsonRpc->instance('\DesSecurity', 'abc');
eJson($des->encrypt('123456')->send(true));
eJson($des->encrypt('789456')->send());
eJson($des->encrypt('025869')->send());

eJson($jsonRpc->redis('redis2')->get('mod_map')->send());
eJson($jsonRpc->redis('redis2')->get('mod_map')->send());
eJson($jsonRpc->redis('redis')->get('mod_map')->send());
eJson($des->encrypt('133333')->send());
