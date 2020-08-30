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

use think\Facade;
use think\Loader;
use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http as WorkerHttp;

/**
 * Worker http server 命令行服务类
 */
class HttpServer extends Server
{
    protected $app;
    protected $appPath;
    protected $root;
    protected $monitor;
    /** @var array Mime mapping. */
    protected static $mimeTypeMap = [];

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
        $this->initMimeTypeMap();
        $this->app = new App($this->appPath);
        $this->lastMtime = time();

        // 指定应用worker
        $this->app::worker($worker);

        // 指定日志类驱动
        Loader::addClassMap([
            'think\\log\\driver\\File' => __DIR__ . '/log/File.php',
        ]);

        Facade::bind([
            'think\facade\App' => App::class,
            'think\facade\Cookie' => Cookie::class,
            'think\facade\Session' => Session::class,
        ]);

        // 应用初始化
        $this->app->initialize();

        $this->app->bindTo([
            'app' => App::class,
            'cookie' => Cookie::class,
            'session' => Session::class,
        ]);

        // Session初始化
        $this->app->session->init();

        if (0 == $worker->id && $this->monitor) {
            $paths = [
                $this->app->getAppPath(),
                $this->app->getConfigPath(),
                $this->app->getRootPath() . '.env',
                $this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR,
                $this->app->getRootPath() . 'extend' . DIRECTORY_SEPARATOR,
                $this->app->getRootPath() . 'vendor' . DIRECTORY_SEPARATOR,
            ];
            $paths = $this->monitor['path'] ? array_merge($paths, $this->monitor['path']) : $paths;
            $timer = $this->monitor['interval'] ?: 2;
            // 扫描目录
            $dest = implode(' ', $paths);
            // 定时扫描
            Timer::add($timer, function () use ($dest, $timer) {
                $seconds = ceil(($timer * 1000) / 1000);
                $minutes = sprintf('-%.2f', $seconds / 60);

                $bin = PHP_OS === 'Darwin' ? 'gfind' : 'find';
                $cmd = "{$bin} {$dest} -mmin {$minutes} -type f -regex \".*\.php\|.*\.html\|.*\.env\"";

                exec($cmd, $output);
                if ($output) {
                    echo '[update: ' . date('H:i:s') . ']' . implode(PHP_EOL, $output) . PHP_EOL;
                    posix_kill(posix_getppid(), SIGUSR1);
                    return;
                }
            });
        }
    }

    /**
     * onMessage 事件回调
     * @access public
     * @param \Workerman\Connection\TcpConnection $connection
     * @param mixed $data
     * @return void
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        $uri = parse_url($_SERVER['REQUEST_URI']);
        $path = isset($uri['path']) ? $uri['path'] : '/';

        $file = $this->root . $path;

        if (!is_file($file)) {
            $this->app->onMessage($connection);
        } else {
            $this->sendFile($connection, $file);
        }
    }

    protected function sendFile(TcpConnection $connection, $file)
    {
        $info = stat($file);
        $modifiyTime = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' ' . date_default_timezone_get() : '';

        if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info) {
            // Http 304.
            if ($modifiyTime === $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
                // 304
                WorkerHttp::header('HTTP/1.1 304 Not Modified');
                // Send nothing but http headers..
                return $connection->close('');
            }
        }

        $mimeType = $this->getMimeType($file);

        WorkerHttp::header('HTTP/1.1 200 OK');
        WorkerHttp::header('Connection: keep-alive');

        if ($mimeType) {
            WorkerHttp::header('Content-Type: ' . $mimeType);
        } else {
            WorkerHttp::header('Content-Type: application/octet-stream');
            $fileinfo = pathinfo($file);
            $filename = isset($fileinfo['filename']) ? $fileinfo['filename'] : '';
            WorkerHttp::header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        if ($modifiyTime) {
            WorkerHttp::header('Last-Modified: ' . $modifiyTime);
        }

        WorkerHttp::header('Content-Length: ' . filesize($file));

        ob_start();
        readfile($file);
        $content = ob_get_clean();

        return $connection->send($content);
    }

    /**
     * 获取文件类型信息
     * @access public
     * @param $filename
     * @return string
     */
    public function getMimeType($filename)
    {
        $file_info = pathinfo($filename);
        $extension = isset($file_info['extension']) ? $file_info['extension'] : '';

        if (isset(self::$mimeTypeMap[$extension])) {
            $mime = self::$mimeTypeMap[$extension];
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $filename);
        }
        return $mime;
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

    /**
     * Init mime map.
     *
     * @return void
     * @throws \Exception
     */
    protected function initMimeTypeMap()
    {
        $mime_file = WorkerHttp::getMimeTypesFile();
        if (!is_file($mime_file)) {
            Worker::log("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($items)) {
            Worker::log("get $mime_file mime.type content fail");
            return;
        }
        foreach ($items as $content) {
            if (preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mime_type = $match[1];
                $workerman_file_extension_var = $match[2];
                $workerman_file_extension_array = explode(' ', substr($workerman_file_extension_var, 0, -1));
                foreach ($workerman_file_extension_array as $workerman_file_extension) {
                    self::$mimeTypeMap[$workerman_file_extension] = $mime_type;
                }
            }
        }
    }

}
