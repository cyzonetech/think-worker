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
    /**
     * Workerman Session操作句柄
     * @var
     */
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
     * session自动启动或者初始化
     * @access public
     * @return void
     */
    public function boot()
    {
        if (is_null($this->init)) {
            $this->init();
        }

        if (false === $this->init) {
            if (PHP_SESSION_ACTIVE != session_status()) {
                $this->start();
            }
            $this->init = true;
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
        // 获取所有SESSION
        $_SESSION = $this->_handler->all();

        $this->init = true;
    }

    /**
     * session设置
     * @access public
     * @param string $name session名称
     * @param mixed $value session值
     * @param string|null $prefix 作用域（前缀）
     * @return void
     */
    public function set($name, $value, $prefix = null)
    {
        empty($this->init) && $this->boot();

        $prefix = !is_null($prefix) ? $prefix : $this->prefix;

        if (strpos($name, '.')) {
            // 二维数组赋值
            list($name1, $name2) = explode('.', $name);
            if ($prefix) {
                $_SESSION[$prefix][$name1][$name2] = $value;
            } else {
                $_SESSION[$name1][$name2] = $value;
            }
        } elseif ($prefix) {
            $_SESSION[$prefix][$name] = $value;
        } else {
            $_SESSION[$name] = $value;
        }
        $this->_handler->put($_SESSION);
    }

    /**
     * 删除session数据
     * @access public
     * @param string|array $name session名称
     * @param string|null $prefix 作用域（前缀）
     * @return void
     */
    public function delete($name, $prefix = null)
    {
        empty($this->init) && $this->boot();

        $prefix = !is_null($prefix) ? $prefix : $this->prefix;

        if (is_array($name)) {
            foreach ($name as $key) {
                $this->delete($key, $prefix);
            }
        } elseif (strpos($name, '.')) {
            list($name1, $name2) = explode('.', $name);
            if ($prefix) {
                unset($_SESSION[$prefix][$name1][$name2]);
            } else {
                unset($_SESSION[$name1][$name2]);
            }
        } else {
            if ($prefix) {
                unset($_SESSION[$prefix][$name]);
            } else {
                unset($_SESSION[$name]);
            }
        }
        $this->_handler->put($_SESSION);
    }

    /**
     * 清空session数据
     * @access public
     * @param string|null $prefix 作用域（前缀）
     * @return void
     */
    public function clear($prefix = null)
    {
        empty($this->init) && $this->boot();
        $prefix = !is_null($prefix) ? $prefix : $this->prefix;

        if ($prefix) {
            unset($_SESSION[$prefix]);
        } else {
            $_SESSION = [];
        }
        $this->_handler->put($_SESSION);
    }

    /**
     * 销毁session
     * @access public
     * @return void
     */
    public function destroy()
    {
        if (!empty($_SESSION)) {
            $_SESSION = [];
            $this->_handler->flush();
        }
        session_unset();
        session_destroy();

        $this->init = null;
    }

}
