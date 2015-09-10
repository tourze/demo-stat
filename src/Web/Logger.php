<?php

namespace stat\Web;

use stat\Base;
use stat\Cache;
use tourze\Base\Config;

class Logger extends Base
{

    public function run($module, $interface, $date, $start_time, $offset, $count)
    {
        $module_str = '';
        foreach (Cache::$modulesDataCache as $mod => $interfaces)
        {
            if ($mod == 'WorkerMan')
            {
                continue;
            }
            $module_str .= '<li><a href="/?fn=statistic&module=' . $mod . '">' . $mod . '</a></li>';
            if ($module == $mod)
            {
                foreach ($interfaces as $if)
                {
                    $module_str .= '<li>&nbsp;&nbsp;<a href="/?fn=statistic&module=' . $mod . '&interface=' . $if . '">' . $if . '</a></li>';
                }
            }
        }

        $log_data_arr = $this->getStasticLog($module, $interface, $start_time, $offset, $count);
        unset($_GET['fn'], $_GET['ip'], $_GET['offset']);
        $log_str = '';
        foreach ($log_data_arr as $address => $log_data)
        {
            list($ip, $port) = explode(':', $address);
            $log_str .= $log_data['data'];
            $_GET['ip'][] = $ip;
            $_GET['offset'][] = $log_data['offset'];
        }
        $log_str = nl2br(str_replace("\n", "\n\n", $log_str));
        $next_page_url = http_build_query($_GET);
        $log_str .= "</br><center><a href='/?fn=logger&$next_page_url'>下一页</a></center>";

        include ROOT_PATH . '/view/header.tpl.php';
        include ROOT_PATH . '/view/log.tpl.php';
        include ROOT_PATH . '/view/footer.tpl.php';
    }

    public function getStasticLog($module, $interface, $start_time, $offset = '', $count = 10)
    {
        $ip_list = ( ! empty($_GET['ip']) && is_array($_GET['ip'])) ? $_GET['ip'] : Cache::$ServerIpList;
        $offset_list = ( ! empty($_GET['offset']) && is_array($_GET['offset'])) ? $_GET['offset'] : [];
        $port = Config::load('statServer')->get('providerPort');
        $request_buffer_array = [];
        foreach ($ip_list as $key => $ip)
        {
            $offset = isset($offset_list[$key]) ? $offset_list[$key] : 0;
            $request_buffer_array["$ip:$port"] = json_encode(['cmd' => 'get_log', 'module' => $module, 'interface' => $interface, 'start_time' => $start_time, 'offset' => $offset, 'count' => $count]) . "\n";
        }

        $read_buffer_array = Base::multiRequest($request_buffer_array);
        ksort($read_buffer_array);
        foreach ($read_buffer_array as $address => $buf)
        {
            list($ip, $port) = explode(':', $address);
            $body_data = json_decode(trim($buf), true);
            $log_data = isset($body_data['data']) ? $body_data['data'] : '';
            $offset = isset($body_data['offset']) ? $body_data['offset'] : 0;
            $read_buffer_array[$address] = ['offset' => $offset, 'data' => $log_data];
        }
        return $read_buffer_array;
    }

}