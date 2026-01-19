RPC(json)服务

**安装**  
    
    mkdir rpc
    cd rpc
    
    composer require myphps/my-rpc
    
    cp vendor/myphps/my-rpc/run.example.php run.php
    cp vendor/myphps/my-rpc/conf.example.php conf.php
    chmod +x run.php
    
    修改 run.php conf.php配置
    运行(tcp模式) ./run.php 
    
**其他**  
    app/control/JsonRpcAct.php 为myphp框架http请求示例

**示例说明**    
`Http模式`
   
`Tcp模式`