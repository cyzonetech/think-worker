<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace think\worker;

use Workerman\Worker;
use Workerman\Protocols\Websocket;

/**
 * Class Request
 * @package think\worker;
 */
class Request extends \Workerman\Protocols\Http\Request
{
    /**
     * Header cache.
     *
     * @var array
     */
    protected static $_serverCache = array();

    /**
     * @var string
     */
    public $app = null;

    /**
     * @var string
     */
    public $controller = null;

    /**
     * @var string
     */
    public $action = null;

    /**
     * @return mixed|null
     */
    public function all()
    {
        return $this->get() + $this->post();
    }

    /**
     * @param $name
     * @param null $default
     * @return null
     */
    public function input($name, $default = null)
    {
        $post = $this->post();
        if (isset($post[$name])) {
            return $post[$name];
        }
        $get = $this->get();
        return isset($get[$name]) ? $get[$name] : $default;
    }

    /**
     * @param array $keys
     */
    public function only(array $keys)
    {
        $post = $this->post();
        $get = $this->get();
        $result = [];
        foreach ($keys as $key) {
            if (isset($post[$key])) {
                $result[$key] = $post[$key];
                continue;
            }
            $result[$key] = isset($get[$key]) ? $get[$key] : null;
        }
    }

    /**
     * @param array $keys
     * @return mixed|null
     */
    public function except(array $keys)
    {
        $all = $this->all();
        foreach ($keys as $key) {
            unset($all[$key]);
        }
        return $all;
    }

    /**
     * @param null $name
     * @return null| UploadFile
     */
    public function file($name = null)
    {
        $file = $this->file($name);
        if (null === $file) {
            return null;
        }
        return new UploadFile($file['tmp_name'], $file['name'], $file['type'], $file['error']);
    }

    /**
     * @return string
     */
    public function getRemoteIp()
    {
        return Application::connection()->getRemoteIp();
    }

    /**
     * @return int
     */
    public function getRemotePort()
    {
        return Application::connection()->getRemotePort();
    }

    /**
     * @return string
     */
    public function getLocalIp()
    {
        return Application::connection()->getLocalIp();
    }

    /**
     * @return int
     */
    public function getLocalPort()
    {
        return Application::connection()->getLocalPort();
    }

    /**
     * @return string
     */
    public function url()
    {
        return '//' . $this->host() . '/' . $this->path();
    }

    /**
     * @return string
     */
    public function fullUrl()
    {
        return '//' . $this->host() . '/' . $this->uri();
    }

    /**
     * @return bool
     */
    public function isAjax()
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * @return bool
     */
    public function isPjax()
    {
        return (bool)$this->header('X-PJAX');
    }

    /**
     * @return bool
     */
    public function expectsJson()
    {
        return ($this->isAjax() && !$this->isPjax()) || $this->acceptJson();
    }

    /**
     * @return bool
     */
    public function acceptJson()
    {
        return false !== strpos($this->header('accept'), 'json');
    }

    /**
     * $_POST.
     *
     * @param $name
     * @param null $default
     * @return mixed|null
     */
    public function server($name = null, $default = null)
    {
        if (!isset($this->_data['server'])) {
            $this->parseServer();
        }
        if (null === $name) {
            return $this->_data['server'];
        }
        return isset($this->_data['server'][$name]) ? $this->_data['server'][$name] : $default;
    }

    /**
     * Parse server.
     *
     * @return void
     */
    protected function parseServer()
    {
        $this->_data['server'] = array();
        $raw_head = $this->rawHead();
        $cacheable = static::$_enableCache && !isset($raw_head[2048]);
        if ($cacheable && isset(static::$_serverCache[$raw_head])) {
            $this->_data['server'] = static::$_serverCache[$raw_head];
            return;
        }

        $microtime = \microtime(true);
        $this->_data['server'] = array(
            'QUERY_STRING' => \parse_url($this->uri(), PHP_URL_QUERY),
            'REQUEST_METHOD' => '',
            'REQUEST_URI' => '',
            'SERVER_PROTOCOL' => '',
            'SERVER_SOFTWARE' => 'workerman/' . Worker::VERSION,
            'SERVER_NAME' => '',
            'HTTP_HOST' => '',
            'HTTP_USER_AGENT' => '',
            'HTTP_ACCEPT' => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
            'HTTP_ACCEPT_ENCODING' => '',
            'HTTP_COOKIE' => '',
            'HTTP_CONNECTION' => '',
            'CONTENT_TYPE' => '',
            'REMOTE_ADDR' => '',
            'REMOTE_PORT' => '0',
            'REQUEST_TIME'         => (int)$microtime,
            'REQUEST_TIME_FLOAT'   => $microtime //compatible php5.4
        );

        $header_data = \explode("\r\n", $raw_head);
        [
            $this->_data['server']['REQUEST_METHOD'],
            $this->_data['server']['REQUEST_URI'],
            $this->_data['server']['SERVER_PROTOCOL']
        ] = \explode(' ', $header_data[0]);
        unset($header_data[0]);

        foreach ($header_data as $content) {
            if (false !== \strpos($content, ':')) {
                list($key, $value) = \explode(':', $content, 2);
                $key = \str_replace('-', '_', strtoupper($key));
                $value = \trim($value);
                $this->_data['server']['HTTP_' . $key] = $value;
                switch ($key) {
                    // HTTP_HOST
                    case 'HOST':
                        $tmp = \explode(':', $value);
                        $this->_data['server']['SERVER_NAME'] = $tmp[0];
                        if (isset($tmp[1])) {
                            $this->_data['server']['SERVER_PORT'] = $tmp[1];
                        }
                        break;
                    // cookie
                    case 'COOKIE':
                        \parse_str(\str_replace('; ', '&', $this->_data['server']['HTTP_COOKIE']), $_COOKIE);
                        break;
                    // content-type
                    case 'CONTENT_TYPE':
                        if (!\preg_match('/boundary="?(\S+)"?/', $value, $match)) {
                            if ($pos = \strpos($value, ';')) {
                                $this->_data['server']['CONTENT_TYPE'] = \substr($value, 0, $pos);
                            } else {
                                $this->_data['server']['CONTENT_TYPE'] = $value;
                            }
                        } else {
                            $this->_data['server']['CONTENT_TYPE'] = 'multipart/form-data';
                            $http_post_boundary = '--' . $match[1];
                        }
                        break;
                    case 'CONTENT_LENGTH':
                        $this->_data['server']['CONTENT_LENGTH'] = $value;
                        break;
                    case 'UPGRADE':
                        if ($value === 'websocket') {
                            $this->connection->protocol = '\Workerman\Protocols\Websocket';
                            return Websocket::input($raw_head, $this->connection);
                        }
                        break;
                }
            } else {
                $key = \str_replace('-', '_', strtoupper($content));
                $this->_data['server'][$key] = '';
            }
        }
        if ($cacheable) {
            static::$_serverCache[$raw_head] = $this->_data['server'];
            if (\count(static::$_serverCache) > 128) {
                unset(static::$_serverCache[key(static::$_serverCache)]);
            }
        }
    }

}