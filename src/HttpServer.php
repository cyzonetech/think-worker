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

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\Facade;
use think\Loader;
use Workerman\Worker;
use Workerman\Lib\Timer;

/**
 * Worker http server 命令行服务类
 */
class HttpServer extends Server
{
    protected $app;
    protected $appPath;
    protected $root;
    protected $monitor;
    protected $lastMtime;

    /**
     * 架构函数
     * @access public
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @param array $context 参数
     */
    public function __construct($host, $port, $context = [])
    {
        $this->worker = new Worker('http://' . $host . ':' . $port, $context);

        // 设置回调
        foreach ($this->event as $event) {
            if (method_exists($this, $event)) {
                $this->worker->$event = [$this, $event];
            }
        }
    }

    public function setRoot($root)
    {
        $this->root = $root;
    }

    public function setAppPath($path)
    {
        $this->appPath = $path;
    }

    public function setStaticOption($name, $value)
    {
        Worker::${$name} = $value;
    }

    public function setMonitor($interval = 2, $path = [])
    {
        $this->monitor['interval'] = $interval;
        $this->monitor['path'] = (array)$path;
    }

    /**
     * 设置参数
     * @access public
     * @param array $option 参数
     * @return void
     */
    public function option(array $option)
    {
        // 设置参数
        if (!empty($option)) {
            foreach ($option as $key => $val) {
                $this->worker->$key = $val;
            }
        }
    }

    /**
     * onWorkerStart 事件回调
     * @access public
     * @param \Workerman\Worker $worker
     * @return void
     * @throws \Exception
     */
    public function onWorkerStart($worker)
    {
        $app = new Application($this->appPath);
        $app->worker($worker);

        $this->lastMtime = time();

        // 指定日志类驱动
        Loader::addClassMap([
            'think\\log\\driver\\File' => __DIR__ . '/log/File.php',
        ]);

        Facade::bind([
            'think\facade\Cookie' => Cookie::class,
            'think\facade\Session' => Session::class,
            facade\Application::class => Application::class,
            facade\HttpServer::class => HttpServer::class,
        ]);

        // 应用初始化
        $app->initialize();

        $app->bindTo([
            'cookie' => Cookie::class,
            'session' => Session::class,
        ]);

        if (0 == $worker->id && $this->monitor) {
            $paths = $this->monitor['path'] ?: [$app->getAppPath(), $app->getConfigPath()];
            $timer = $this->monitor['interval'] ?: 2;

            Timer::add($timer, function () use ($paths) {
                foreach ($paths as $path) {
                    $dir = new RecursiveDirectoryIterator($path);
                    $iterator = new RecursiveIteratorIterator($dir);

                    foreach ($iterator as $file) {
                        if (pathinfo($file, PATHINFO_EXTENSION) != 'php') {
                            continue;
                        }

                        if ($this->lastMtime < $file->getMTime()) {
                            echo '[update]' . $file . "\n";
                            posix_kill(posix_getppid(), SIGUSR1);
                            $this->lastMtime = $file->getMTime();
                            return;
                        }
                    }
                }
            });
        }

        $worker->onMessage = [$app, 'onMessage'];
    }

    /**
     * 启动
     * @access public
     * @return void
     */
    public function start()
    {
        Worker::runAll();
    }

    /**
     * 停止
     * @access public
     * @return void
     */
    public function stop()
    {
        Worker::stopAll();
    }

}
