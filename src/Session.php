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

use think\Session as BaseSession;
use think\worker\session\FileSessionHandler;
use think\worker\session\RedisSessionHandler;
use Workerman\Protocols\Http as WorkerHttp;
use Workerman\Protocols\Http\Session as WorkSession;


/**
 * Workerman Cookie类
 */
class Session extends BaseSession
{
    protected $_handler;

    /**
     * session初始化
     * @access public
     * @param array $config
     * @return void
     * @throws \think\Exception
     */
    public function init(array $config = [])
    {
        $config = $config ?: $this->config;

        $isDoStart = false;

        WorkerHttp::sessionName($config['name'] ?? 'PHPSESSID');

        if (isset($config['use_trans_sid'])) {
            ini_set('session.use_trans_sid', $config['use_trans_sid'] ? 1 : 0);
        }

        // 启动session
        if (!empty($config['auto_start']) && PHP_SESSION_ACTIVE != session_status()) {
            ini_set('session.auto_start', 0);
            $isDoStart = true;
        }

        if (isset($config['prefix'])) {
            $this->prefix = $config['prefix'];
        }

        if (isset($config['use_lock'])) {
            $this->lock = $config['use_lock'];
        }

        if (isset($config['domain'])) {
            ini_set('session.cookie_domain', $config['domain']);
        }

        if (isset($config['expire'])) {
            ini_set('session.gc_maxlifetime', $config['expire']);
            ini_set('session.cookie_lifetime', $config['expire']);
        }

        if (isset($config['secure'])) {
            ini_set('session.cookie_secure', $config['secure']);
        }

        if (isset($config['httponly'])) {
            ini_set('session.cookie_httponly', $config['httponly']);
        }

        if (isset($config['use_cookies'])) {
            ini_set('session.use_cookies', $config['use_cookies'] ? 1 : 0);
        }

        if (isset($config['cache_limiter'])) {
            session_cache_limiter($config['cache_limiter']);
        }

        if (isset($config['cache_expire'])) {
            session_cache_expire($config['cache_expire']);
        }
        $config['type'] = $config['type'] ?: 'file';

        if ($config['type'] == 'file') {
            $config['save_path'] = app()->getRuntimePath() . 'sessions';
        } elseif ($config['type'] == 'redis') {
            // redis配置
            $redis = [
                'host' => '127.0.0.1', // 必选参数
                'port' => 6379, // 必选参数
                'auth' => '', // 可选参数
                'select' => 0, // 可选参数
                'timeout' => 2, // 可选参数
                'expire' => 3600, // 有效期(秒)
                'persistent' => true, // 是否长连接
                'prefix' => 'sess_' // 可选参数
            ];
            $config = array_merge($redis, $config);
        }
        $this->config = $config;

        $handlers = [
            'file' => FileSessionHandler::class,
            'redis' => RedisSessionHandler::class
        ];
        WorkSession::handlerClass($handlers[$config['type']], $config);

        if ($isDoStart) {
            $this->start();
        } else {
            $this->init = false;
        }
    }

    /**
     * 启动session
     * @access public
     * @return void
     */
    public function start()
    {
        $this->_handler = App::workerRequest()->session();

        $this->init = true;
    }

    /**
     * 暂停session
     * @access public
     * @return void
     */
    public function pause()
    {
        // 暂停session
        //WorkerHttp::sessionWriteClose();
        print_r('do pause');
        $this->init = false;
    }
}
