<?php
/**
 * Created by PhpStorm.
 * User: shenzhe
 * Date: 2016/10/14
 * Time: 11:06
 */

namespace ZPHP\Client\Rpc;

use ZPHP\Core\Config;

use ZPHP\CLient\Monitor\Client as MClient;
use ZPHP\Protocol\Request;

abstract class Tcp
{
    private static $clients = [];
    private static $configs = [];
    private $client;
    private $api = '';
    private $sync = 1;

    private $config = [];

    /**
     * Tcp constructor.
     * @param $ip
     * @param $port
     * @param int $timeOut
     * @param array $config
     * @throws \Exception
     */
    public function __construct($ip, $port, $timeOut = 500, $config = array())
    {
        if (empty($timeOut) || $timeOut < 1) {
            $timeOut = 500;
        }
        $key = $ip . ':' . $port . ':' . $timeOut;
        if (!isset(self::$clients[$key])) {
            $client = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_SYNC);
            if (empty($config)) {
                $config = [
                    'open_length_check' => true,
                    'package_length_type' => 'N',
                    'package_length_offset' => 0,       //第N个字节是包长度的值
                    'package_body_offset' => 4,       //第几个字节开始计算长度
                    'package_max_length' => 2000000,  //协议最大长度
                    'ctrl_name' => 'a',
                    'method_name' => 'm',
                ];
                $socketConfig = Config::get('socket');
                if (!empty($socketConfig)) {
                    foreach ($config as $key => &$val) {
                        if (isset($socketConfig[$key])) {
                            $val = $socketConfig[$key];
                        }
                    }
                }
                unset($val);
            } else {
                $config = $config + [
                        'open_length_check' => true,
                        'package_length_type' => 'N',
                        'package_length_offset' => 0,       //第N个字节是包长度的值
                        'package_body_offset' => 4,       //第几个字节开始计算长度
                        'package_max_length' => 2000000,  //协议最大长度
                        'ctrl_name' => 'a',
                        'method_name' => 'm',
                    ];
            }

            $client->set($config);
            $ret = $client->connect($ip, $port, $timeOut / 1000);
            if ($ret) {
                self::$clients[$key] = $client;
                self::$configs[$key] = $config;
            } else {
                throw new \Exception('connect server error', -1);
            }

            $this->api = Config::getField('project', 'default_ctrl_name');
        }
        $this->client = self::$clients[$key];
        $this->config = self::$configs[$key];
        return true;
    }

    public function setApi($api)
    {
        $this->api = $api;
        return $this;
    }

    public function noSync()
    {
        $this->sync = 0;
        return $this;
    }

    public function getClient()
    {
        return $this->client;
    }

    public function isConnected()
    {
        if (empty($this->client)) {
            return false;
        }

        return $this->client->isConnected();
    }

    abstract function pack($sendArr);
    abstract function unpack($result);

    /**
     * @param $method
     * @param array $params
     * @return string
     * @desc 远程rpc调用
     */
    public function call($method, $params = [])
    {
        Request::setRequestId();
        $startTime = microtime(true);
        $sendArr = [
            '_recv' => $this->sync,
            $this->config['method_name'] => $method,
        ];
        if ($this->api) {
            $sendArr[$this->config['ctrl_name']] = $this->api;
        }
        $sendArr += $params;
        $result = $this->rawCall($this->pack($sendArr));
        $executeTime = microtime(true) - $startTime;

        MClient::clientDot($this->api . DS . $method, $executeTime);
        return $this->unpack($result);
    }

    /**
     * @param $sendData
     * @return string
     * @throws \Exception
     * @desc 直接发送原始远程rpc调用
     */
    public function rawCall($sendData)
    {
        $sendLen = $this->client->send(pack($this->config['package_length_type'], strlen($sendData)) . $sendData);
        if ($sendLen) {
            $recvData = $this->client->recv();
            if (is_null($recvData)) {
                throw new \Exception('receive data error', -1);
            }
            return substr($recvData, $this->config['package_body_offset']);
        }
        throw new \Exception("send error", $this->client->errCode);
    }

    public function __call($name, $arguments)
    {
        if (empty($arguments[0])) {
            $arguments[0] = [];
        } elseif (!is_array($arguments[0])) {
            throw new \Exception('arguments[0] must array');
        }
        return $this->call($name, $arguments[0]);
    }
}