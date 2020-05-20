<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\worker;

use think\App;
use think\Error;
use think\exception\HttpException;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * Worker应用对象
 */
class Application extends App
{
    /**
     * @var Worker
     */
    protected static $_worker = null;
    /**
     * @var TcpConnection
     */
    protected static $_connection = null;
    /**
     * @var Request
     */
    protected static $_request = null;

    /**
     * App constructor.
     * @param Worker $worker
     * @param $request_class
     */
    public function __construct($appPath = '', Worker $worker)
    {
        parent::__construct();
        static::$_worker = $worker;
    }

    /**
     * @return Request
     */
    public static function request()
    {
        return static::$_request;
    }

    /**
     * @return TcpConnection
     */
    public static function connection()
    {
        return static::$_connection;
    }

    /**
     * @return Worker
     */
    public static function worker()
    {
        return static::$_worker;
    }

    /**
     * 处理Worker请求
     * @access public
     * @param TcpConnection $connection
     * @param Request $request
     * @param void
     */
    public function onMessage(TcpConnection $connection, $request)
    {
        try {
            static::$_request = $request;
            static::$_connection = $connection;

            $uri = parse_url($_SERVER['REQUEST_URI']);
            $path = isset($uri['path']) ? $uri['path'] : '/';

            $file = $this->root . $path;

            if (!is_file($file)) {
                ob_start();
                // 重置应用的开始时间和内存占用
                $this->beginTime = microtime(true);
                $this->beginMem = memory_get_usage();

                // 销毁当前请求对象实例
                $this->delete('think\Request');

                $pathinfo = ltrim(strpos($_SERVER['REQUEST_URI'], '?')
                    ? strstr($_SERVER['REQUEST_URI'], '?', true)
                    : $_SERVER['REQUEST_URI'], '/');

                $this->request->setPathinfo($pathinfo)->withInput($GLOBALS['HTTP_RAW_REQUEST_DATA']);

                // 更新请求对象实例
                $this->route->setRequest($this->request);

                $response = $this->run();
                $response->send();
                $content = ob_get_clean();

                // Trace调试注入
                if ($this->env->get('app_trace', $this->config->get('app_trace'))) {
                    $this->debug->inject($response, $content);
                }

                static::send(
                    $connection,
                    new Response($response->getCode(), $response->getHeader(), $content),
                    $request
                );
            } else {
                static::send($connection, (new Response())->file($file), $request);
            }
        } catch (HttpException $e) {
            $this->exception($connection, $e, $request);
        } catch (\Exception $e) {
            $this->exception($connection, $e, $request);
        } catch (\Throwable $e) {
            $this->exception($connection, $e, $request);
        }
    }

    protected function exception(TcpConnection $connection, $e, Request $request)
    {
        if ($e instanceof \Exception) {
            $handler = Error::getExceptionHandler();
            $handler->report($e);

            $resp = $handler->render($e);
            $content = $resp->getContent();
            $code = $resp->getCode();

            static::send($connection, new Response($code, [], $content), $request);
        } else {
            static::send($connection, new Response(500, [], $e->getMessage()),  $request);
        }
    }

    /**
     * @param TcpConnection $connection
     * @param $response
     * @param Request $request
     */
    protected static function send(TcpConnection $connection, $response, Request $request)
    {
        static::$_connection = static::$_request = null;
        $keepAlive = $request->header('connection');
        if (($keepAlive === null && $request->protocolVersion() === '1.1')
            || $keepAlive === 'keep-alive' || $keepAlive === 'Keep-Alive') {
            $connection->send($response);
            return;
        }
        $connection->close($response);
    }

}
