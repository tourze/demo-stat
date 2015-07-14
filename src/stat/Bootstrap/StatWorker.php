<?php

namespace stat\Bootstrap;

use tourze\Base\Config;
use Workerman\Worker;
use Workerman\Lib\Timer;

class StatWorker extends Worker
{
    /**
     *  最大日志buffer，大于这个值就写磁盘
     *
     * @var integer
     */
    const MAX_LOG_BUFFER_SZIE = 1024000;

    /**
     * 多长时间写一次数据到磁盘
     *
     * @var integer
     */
    const WRITE_PERIOD_LENGTH = 60;

    /**
     * 多长时间清理一次老的磁盘数据
     *
     * @var integer
     */
    const CLEAR_PERIOD_LENGTH = 86400;

    /**
     * 数据多长时间过期
     *
     * @var integer
     */
    const EXPIRED_TIME = 1296000;

    /**
     * 统计数据
     * ip=>modid=>interface=>['code'=>[xx=>count,xx=>count],'suc_cost_time'=>xx,'fail_cost_time'=>xx, 'suc_count'=>xx, 'fail_count'=>xx]
     *
     * @var array
     */
    protected $statisticData = [];

    /**
     * 日志的buffer
     *
     * @var string
     */
    protected $logBuffer = '';

    /**
     * 放统计数据的目录
     *
     * @var string
     */
    protected $statisticDir = 'statistic/statistic/';

    /**
     * 存放统计日志的目录
     *
     * @var string
     */
    protected $logDir = 'statistic/log/';

    /**
     * 提供统计查询的socket
     *
     * @var resource
     */
    protected $providerSocket = null;

    public function __construct($socket_name)
    {
        parent::__construct($socket_name);
        $this->onWorkerStart = [$this, 'onStart'];
        $this->onMessage = [$this, 'onMessage'];
    }

    /**
     * 业务处理
     *
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function onMessage($connection, $data)
    {
        // 解码
        $module = $data['module'];
        $interface = $data['interface'];
        $cost_time = $data['cost_time'];
        $success = $data['success'];
        $time = $data['time'];
        $code = $data['code'];
        $msg = str_replace("\n", "<br>", $data['msg']);
        $ip = $connection->getRemoteIp();

        // 模块接口统计
        $this->collectStatistics($module, $interface, $cost_time, $success, $ip, $code, $msg);
        // 全局统计
        $this->collectStatistics('WorkerMan', 'Statistics', $cost_time, $success, $ip, $code, $msg);

        // 失败记录日志
        if ( ! $success)
        {
            $this->logBuffer .= date('Y-m-d H:i:s', $time) . "\t$ip\t$module::$interface\tcode:$code\tmsg:$msg\n";
            if (strlen($this->logBuffer) >= self::MAX_LOG_BUFFER_SZIE)
            {
                $this->writeLogToDisk();
            }
        }
    }

    /**
     * 收集统计数据
     *
     * @param string $module
     * @param string $interface
     * @param float  $cost_time
     * @param int    $success
     * @param string $ip
     * @param int    $code
     * @param string $msg
     * @return void
     */
    protected function collectStatistics($module, $interface, $cost_time, $success, $ip, $code, $msg)
    {
        // 统计相关信息
        if ( ! isset($this->statisticData[$ip]))
        {
            $this->statisticData[$ip] = [];
        }
        if ( ! isset($this->statisticData[$ip][$module]))
        {
            $this->statisticData[$ip][$module] = [];
        }
        if ( ! isset($this->statisticData[$ip][$module][$interface]))
        {
            $this->statisticData[$ip][$module][$interface] = ['code' => [], 'suc_cost_time' => 0, 'fail_cost_time' => 0, 'suc_count' => 0, 'fail_count' => 0];
        }
        if ( ! isset($this->statisticData[$ip][$module][$interface]['code'][$code]))
        {
            $this->statisticData[$ip][$module][$interface]['code'][$code] = 0;
        }
        $this->statisticData[$ip][$module][$interface]['code'][$code]++;
        if ($success)
        {
            $this->statisticData[$ip][$module][$interface]['suc_cost_time'] += $cost_time;
            $this->statisticData[$ip][$module][$interface]['suc_count']++;
        }
        else
        {
            $this->statisticData[$ip][$module][$interface]['fail_cost_time'] += $cost_time;
            $this->statisticData[$ip][$module][$interface]['fail_count']++;
        }
    }

    /**
     * 将统计数据写入磁盘
     *
     * @return void
     */
    public function writeStatisticsToDisk()
    {
        $time = time();
        // 循环将每个ip的统计数据写入磁盘
        foreach ($this->statisticData as $ip => $mod_if_data)
        {
            foreach ($mod_if_data as $module => $items)
            {
                // 文件夹不存在则创建一个
                $file_dir = Config::load('statServer')->get('dataPath') . $this->statisticDir . $module;
                if ( ! is_dir($file_dir))
                {
                    umask(0);
                    mkdir($file_dir, 0777, true);
                }
                // 依次写入磁盘
                foreach ($items as $interface => $data)
                {
                    file_put_contents($file_dir . "/{$interface}." . date('Y-m-d'), "$ip\t$time\t{$data['suc_count']}\t{$data['suc_cost_time']}\t{$data['fail_count']}\t{$data['fail_cost_time']}\t" . json_encode($data['code']) . "\n", FILE_APPEND | LOCK_EX);
                }
            }
        }
        // 清空统计
        $this->statisticData = [];
    }

    /**
     * 将日志数据写入磁盘
     *
     * @return void
     */
    public function writeLogToDisk()
    {
        // 没有统计数据则返回
        if (empty($this->logBuffer))
        {
            return;
        }
        // 写入磁盘
        file_put_contents(Config::load('statServer')->get('dataPath') . $this->logDir . date('Y-m-d'), $this->logBuffer, FILE_APPEND | LOCK_EX);
        $this->logBuffer = '';
    }

    /**
     * 初始化
     * 统计目录检查
     * 初始化任务
     *
     * @see Man\Core.SocketWorker::onStart()
     */
    protected function onStart()
    {
        // 初始化目录
        umask(0);
        $statistic_dir = Config::load('statServer')->get('dataPath') . $this->statisticDir;
        if ( ! is_dir($statistic_dir))
        {
            mkdir($statistic_dir, 0777, true);
        }
        $log_dir = Config::load('statServer')->get('dataPath') . $this->logDir;
        if ( ! is_dir($log_dir))
        {
            mkdir($log_dir, 0777, true);
        }
        // 定时保存统计数据
        Timer::add(self::WRITE_PERIOD_LENGTH, [$this, 'writeStatisticsToDisk']);
        Timer::add(self::WRITE_PERIOD_LENGTH, [$this, 'writeLogToDisk']);
        // 定时清理不用的统计数据
        Timer::add(self::CLEAR_PERIOD_LENGTH, [$this, 'clearDisk'], [Config::load('statServer')->get('dataPath') . $this->statisticDir, self::EXPIRED_TIME]);
        Timer::add(self::CLEAR_PERIOD_LENGTH, [$this, 'clearDisk'], [Config::load('statServer')->get('dataPath') . $this->logDir, self::EXPIRED_TIME]);

    }

    /**
     * 进程停止时需要将数据写入磁盘
     *
     * @see Man\Core.SocketWorker::onStop()
     */
    protected function onStop()
    {
        $this->writeLogToDisk();
        $this->writeStatisticsToDisk();
    }

    /**
     * 清除磁盘数据
     *
     * @param string $file
     * @param int    $exp_time
     */
    public function clearDisk($file = null, $exp_time = 86400)
    {
        $time_now = time();
        if (is_file($file))
        {
            $mtime = filemtime($file);
            if ( ! $mtime)
            {
                $this->notice("filemtime $file fail");
                return;
            }
            if ($time_now - $mtime > $exp_time)
            {
                unlink($file);
            }
            return;
        }
        foreach (glob($file . "/*") as $file_name)
        {
            $this->clearDisk($file_name, $exp_time);
        }
    }
} 
