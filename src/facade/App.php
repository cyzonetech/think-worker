<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\worker\facade;

use think\Facade;

/**
 * @see \think\worker\App
 * @mixin \think\worker\App
 * @method void initialize() static 初始化应用
 * @method void onMessage(\Workerman\Connection\TcpConnection $connection, $request) static 处理Worker请求
 * @method void workerRequest() static 获取request
 */
class App extends Facade
{

}
