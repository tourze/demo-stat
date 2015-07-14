<?php

use stat\Bootstrap\StatProvider;
use stat\Bootstrap\StatWorker;
use Workerman\Worker;
use Workerman\WebServer;

require 'bootstrap.php';

// StatProvider
$statistic_provider = new StatProvider("Text://0.0.0.0:55858");
$statistic_provider->name = 'StatProvider';

// StatWorker
$statistic_worker = new StatWorker("Statistic://0.0.0.0:55656");
$statistic_worker->transport = 'udp';
$statistic_worker->name = 'StatWorker';

// WebServer
$web = new WebServer("http://0.0.0.0:55757");
$web->name = 'StatisticWeb';
$web->addRoot('stat.tourze.com', ROOT_PATH . 'web');

// recv udp broadcast
$udp_finder = new Worker("Text://0.0.0.0:55858");
$udp_finder->name = 'StatisticFinder';
$udp_finder->transport = 'udp';
$udp_finder->onMessage = function ($connection, $data)
{
    $data = json_decode($data, true);
    if (empty($data))
    {
        return false;
    }

    // 无法解析的包
    if (empty($data['cmd']) || $data['cmd'] != 'REPORT_IP')
    {
        return false;
    }

    // response
    return $connection->send(json_encode(['result' => 'ok']));
};

// 运行所有服务
Worker::runAll();
