<?php

namespace WHMCS\Module\Server\vmmanager6;

use WHMCS\UsageBilling\Contracts\Metrics\MetricInterface;
use WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface;
use WHMCS\UsageBilling\Metrics\Metric;
use WHMCS\UsageBilling\Metrics\Units\GigaBytes;
use WHMCS\UsageBilling\Metrics\Usage;
use WHMCS\Database\Capsule as DB;

class VMMetricProvider implements ProviderInterface
{
    private $params = [];

    public function __construct($params)
    {
        $this->params = $params;
    }

    public function metrics()
    {
        return [
            new Metric('bwlimit', 'Bandwidth limit', MetricInterface::TYPE_SNAPSHOT, new GigaBytes()),
        ];
    }

    public function usage()
    {
        $products_id = DB::table('tblproducts')->where('type', 'server')->where('servertype', 'vmmanager6')->pluck('id');
        $hosts = DB::table('tblhosting')->select('tblhosting.id', 'tblhosting.domain', 'mod_ispsystem.external_id')->join('mod_ispsystem', 'mod_ispsystem.serviceid', '=', 'tblhosting.id')->where('tblhosting.domainstatus', 'Active')->whereIn('tblhosting.packageid', $products_id)->get();
        $admin = vmmanager6_get_admin($this->params);
        $usage = [];
        foreach ($hosts as $host) {
            $metric_data = $admin->get('vm/v3/host/' . $host->external_id . '/metrics?target=net_rx_summary&output=single&interval=1month');
            if (sizeof($metric_data) == 0) {
                throw new \ISPsystem\LogicError("Empty metric_data");
            }
            $data = ['bwlimit' => round($metric_data[0]->datapoints[0][0] / 1024 / 1024 / 1024, 2)];
            $usage[$host->domain] = $this->wrapUserData($data);
        }

        return $usage;
    }

    public function tenantUsage($tenant)
    {
        $this->params['serviceid'] = DB::table('tblhosting')->where('domain', $tenant)->value('id');
        $vm_id = vmmanager6_get_external_id($this->params);
        if (empty($vm_id)) {
            return [];
        }

        $admin = vmmanager6_get_admin($this->params);
        $metric_data = $admin->get('vm/v3/host/' . $vm_id . '/metrics?target=net_rx_summary&output=single&interval=1month');
        if (sizeof($metric_data) == 0) {
            throw new \ISPsystem\LogicError("Empty metric_data");
        }
        $data = ['bwlimit' => round($metric_data[0]->datapoints[0][0] / 1024 / 1024 / 1024, 2)];

        return $this->wrapUserData($data);
    }

    private function wrapUserData($data)
    {
        $wrapped = [];
        foreach ($this->metrics() as $metric) {
            $key = $metric->systemName();
            if (isset($data[$key])) {
                $metric = $metric->withUsage(new Usage($data[$key]));
            }
            $wrapped[] = $metric;
        }

        return $wrapped;
    }
}
