<?php

/**
 * VMmanager 6 integration module
 *
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright Copyright (c) ISPsystem LLC 2019
 * @link      http://www.ispsystem.com/
 */

use WHMCS\Database\Capsule as DB;
use WHMCS\Module\Server\vmmanager6\VMMetricProvider;

/**
 * Direct access forbidden
 */
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$vmmanager6_load_ispsystem_libs_processed = false;
function vmmanager6_load_ispsystem_libs()
{
    if ($GLOBALS["vmmanager6_load_ispsystem_libs_processed"]) {
        return;
    }
    $path = dirname(__FILE__);
    $path = substr($path, 0, strpos($path, "servers/vmmanager6"));
    require_once $path . "includes/ispsystem/6/api.php";
    require_once $path . "includes/ispsystem/6/utils.php";
    require_once $path . "servers/vmmanager6/lib/vmmanager6.php";
    $GLOBALS["vmmanager6_load_ispsystem_libs_processed"] = true;
}

function vmmanager6_user_has_role($roles, $expected_role, $strict = true)
{
    $check = false;
    foreach ($roles as $value) {
        if ($strict) {
            $check = (string) $value == $expected_role;
        } else {
            $check = (strpos($value, $expected_role) !== false);
        }

        if ($check) {
            break;
        }
    }

    return $check;
}

function vmmanager6_get_admin($params)
{
    vmmanager6_load_ispsystem_libs();
    $addr = isset($params["serverip"]) && strlen($params["serverip"]) > 0
            ? $params["serverip"]
            : $params["serverhostname"];
    $admin = \ISPsystem\AuthV4::admin(
        new \ISPsystem\API($addr),
        $params["serverusername"],
        $params["serverpassword"]
    );
    $account_info = $admin->get("vm/v3/account?where=(email+EQ+'" . $params["serverusername"] . "')")->{"list"};

    if (sizeof($account_info) != 0) {
        if (!vmmanager6_user_has_role($account_info[0]->{"roles"}, "@admin")) {
            throw new \ISPsystem\LogicError("No admin permissions");
        }
        return $admin;
    }

    throw new \ISPsystem\LogicError("User not found");
}

function vmmanager6_get_user_mail($params)
{
    $user_email = $params["clientsdetails"]["email"];
    $check_email_verifing_option = (bool)($params["configoption11"] == "on");
    if (!$check_email_verifing_option) {
        return $user_email;
    }

    try {
        $is_user_email_verified = (bool)$params["model"]["relations"]["client"]["attributes"]["email_verified"];
        if ($is_user_email_verified) {
            return $user_email;
        }

        throw new \ISPsystem\LogicError("User email is not verified");
    } catch (Exception $e) {
        throw new \ISPsystem\LogicError("Email verify field not found: " . $e->getMessage());
    }
}

$vmmanager6_whmcs_admin = null;
function vmmanager6_local_api_call(string $func, array $param)
{
    if (!$GLOBALS["vmmanager6_whmcs_admin"]) {
        $GLOBALS["vmmanager6_whmcs_admin"] = DB::table('tbladmins')
            ->leftJoin('tbladminperms', 'tbladmins.roleid', '=', 'tbladminperms.roleid')
            ->where(
                [
                    ['tbladmins.disabled', '=', 0],
                    ['tbladminperms.permid', '=', 81],
                ]
            )
            ->select('tbladmins.username')
            ->first()->username;
    }
    return localAPI($func, $param, $GLOBALS["vmmanager6_whmcs_admin"]);
}

function vmmanager6_get_external_id($params)
{
    $result = DB::table('mod_ispsystem')
        ->select('external_id')
        ->where(
            [
                ['serviceid', $params["serviceid"]],
                ['external_id', '<>', ''],
            ]
        )
        ->first();
    if ($result) {
        return $result->external_id;
    } else {
        return "";
    }
}

function vmmanager6_save_external_id($params, $external_id)
{
    $vmid = vmmanager6_get_external_id($params);

    if ($vmid !== "") {
        DB::table('mod_ispsystem')->where('serviceid', $params["serviceid"])->update(['external_id' => $external_id]);
    } else {
        DB::table('mod_ispsystem')->insert(['external_id' => $external_id, 'serviceid' => $params["serviceid"]]);
    }
}

function vmmanager6_get_vm_info($admin, $vm_id)
{
    $vm_info = $admin->get("vm/v3/host?where=(id+EQ+'" . $vm_id . "')")->{"list"};

    if (sizeof($vm_info) == 0) {
        throw new \ISPsystem\LogicError("VM " . $vm_id . " not found");
    }

    return $vm_info[0];
}

function vmmanager6_MetaData()
{
    return array(
        "DisplayName" => "VMmanager 6",
        "APIVersion" => "1.1",
        "RequiresServer" => true,
        'AdminSingleSignOnLabel' => 'Login to VMmanager 6',
        'ServiceSingleSignOnLabel' => 'Login to VMmanager 6',
    );
}

function vmmanager6_ConfigOptions()
{
    return [
        "cluster" => [
            "FriendlyName" => "Cluster ID",
            "Type" => "text",
            "Size" => "8",
        ],
        "source_type" => [
            "FriendlyName" => "Source type",
            "Type" => "radio",
            "Options" => "os,image",
            "Default" => "os",
        ],
        "source_id" => [
            "FriendlyName" => "Source ID",
            "Type" => "text",
            "Size" => "8",
        ],
        "cpu_number" => [
            "FriendlyName" => "vCPU count",
            "Type" => "text",
            "Size" => "8",
        ],
        "ram_mib" => [
            "FriendlyName" => "RAM",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MiB",
        ],
        "hdd_mib" => [
            "FriendlyName" => "Disk size",
            "Type" => "text",
            "Size" => "8",
            "Description" => "MiB",
        ],
        "net_bandwidth_mbitps" => [
            "FriendlyName" => "Network bandwidth",
            "Type" => "text",
            "Size" => "8",
            "Description" => "Mbit/s",
        ],
        "ipv4_pool" => [
            "FriendlyName" => "IP pool ID",
            "Type" => "text",
            "Size" => "8",
            "Default" => "",
        ],
        "recipe_id" => [
            "FriendlyName" => "Recipe ID",
            "Type" => "text",
            "Size" => "8",
            "Default" => "",
        ],
        "waiter" => [
            "FriendlyName" => "Dont wait the OS install",
            "Type" => "yesno",
            "Description" => "Activate service without waiting for the OS installation",
        ],
        "checks_email_verification" => [
            "FriendlyName" => "User email verifying option check",
            "Type" => "yesno",
            "Description" => "Disable service management for users with unverified email",
            "Default" => "yes",
        ],
        "show_statistic" => [
            "FriendlyName" => "Show statistic",
            "Type" => "yesno",
            "Description" => "Show statistic usage for virtual server",
        ],
        "allow_change_ptr" => [
            "FriendlyName" => "Allow change PTR",
            "Type" => "yesno",
        ],
        "is_vxlan_count" => [
            "FriendlyName" => "It is VxLAN count",
            "Type" => "yesno",
        ],
    ];
}

function _vmmanager6_test_connection(array $params)
{
    if (strlen($params["serverusername"]) == 0) {
        throw new \ISPsystem\LogicError("Empty username");
    }
    if (!filter_var($params["serverusername"], FILTER_VALIDATE_EMAIL)) {
        throw new \ISPsystem\LogicError("Username must be a valid email address");
    }
    if (strlen($params["serverpassword"]) == 0) {
        throw new \ISPsystem\LogicError("Empty password");
    }
    vmmanager6_get_admin($params);
}

function vmmanager6_TestConnection(array $params)
{
    vmmanager6_load_ispsystem_libs();
    $r = \ISPsystem\safeCall("_vmmanager6_test_connection", $params, __FUNCTION__);
    if ($r == "success") {
        return array(
        "success" => true,
        "error" => "",
        );
    }
    return array(
        "success" => false,
        "error" => $r,
    );
}

function _vmmanager6_create_account(array $params)
{
    $admin = vmmanager6_get_admin($params);

    $user_email = vmmanager6_get_user_mail($params);
    $user_info = $admin->get("vm/v3/account?where=(email+EQ+'" . $user_email . "')")->{"list"};
    $user_exists = sizeof($user_info) != 0;

    if (!$user_exists) {
        $user_password = DB::table('tblhosting')
            ->select('password')
            ->where('id', $params["serviceid"])
            ->first()->password;
        if (strlen($user_password) == 0) {
            $user_password = \ISPsystem\randomString();
        } else {
            $user_password = \ISPsystem\DecryptPassword($user_password);
        }
        // validate email
        if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
            throw new \ISPsystem\LogicError("Invalid customer email");
        }

        // create new user
        $user_id = $admin->post(
            "vm/v3/account",
            array(
                "email" => $user_email,
                "password" => $user_password,
                "role" => "@user",
            )
        )->{"id"};
        // save username and password
        DB::table('tblhosting')
            ->where('id', $params["serviceid"])
            ->update(
                [
                "username" => $user_email,
                "password" => \ISPsystem\encryptPassword($user_password),
                ]
            );
    } else {
        if (vmmanager6_user_has_role($user_info[0]->{"roles"}, "@admin", false)) {
            throw new \ISPsystem\LogicError("This function is not allowed for VMmanager 6 administrators");
        }
        $user_id = $user_info[0]->{"id"};
        DB::table('tblhosting')
            ->where('id', $params["serviceid"])
            ->update(
                [
                "username" => $user_email,
                ]
            );
    }

    $is_vxlan_count = (bool)($params['configoption14'] === 'on');
    if ($is_vxlan_count) {
        $admin->post("vm/v3/user_limits/account/{$user_id}", [
            'vxlan_count_total' => (int)$params['configoptions']['vxlan_count'],
        ]);
        return;
    }

    $vm_create_params = array(
        "name" => "cont" . $params["serviceid"],
        "password" => $params["password"],
        "cluster" => (int)$params["configoption1"],
        $params["configoption2"] => (int)$params["configoption3"], // OS or Image
        "cpu_number" => (int)$params["configoption4"],
        "ram_mib" => (int)$params["configoption5"],
        "hdd_mib" => (int)$params["configoption6"],
        "account" => (int)$user_id,
        "ipv4_number" => 1,
        "ipv6_enabled" => false,
    );

    /*if (!empty($params["configoption12"])) { // Ipv6 Subnet
        $vm_create_params["ipv6_enabled"] = (bool)($params["configoption12"] == "on");
    }*/

    if (!empty($params["domain"])) {
        // TODO (bryukhanov) Добавить валидацию доменного имени
        $vm_create_params["domain"] = strtolower($params["domain"]);
    }

    if (!empty($params["configoption7"])) {
        $vm_create_params["net_bandwidth_mbitps"] = (int)$params["configoption7"];
    }

    if (!empty($params["configoption8"])) {
        $vm_create_params["ipv4_pool"] = [(int)$params["configoption8"]];
    }

    if (!empty($params["configoption9"])) {
        $vm_create_params["recipe"] = (int)$params["configoption9"];
    }

    $enabled_options = array(
        "cluster",
        "os",
        "recipe",
        "ipv4_number",
        "cpu_number",
        "ram_mib",
        "hdd_mib",
        "net_bandwidth_mbitps",
    );

    $enabled_options_boolean = array(
        "ipv6_enabled",
    );

    foreach ($params["configoptions"] as $key => $value) {
        if (in_array($key, $enabled_options)) {
            // Сетевой трафик складываем, чтобы можно было в тарифе задать включенный в тариф трафик
            if ($key === 'net_bandwidth_mbitps' && !empty($vm_create_params[$key])) {
                $vm_create_params[$key] += (int)$value;
            } elseif ($key === 'recipe') {
                if (!empty($value)) {
                    $vm_create_params[$key] = (int)$value;
                }
            } else {
                $vm_create_params[$key] = (int)$value;
            }
        } else if (in_array($key, $enabled_options_boolean)) {
            $vm_create_params[$key] = (bool)$value;
        }
    }

    if (!empty($params['configoptions']['ipv6_prefix']) && $vm_create_params['ipv6_enabled']) {
        $vm_create_params['ipv6_prefix'] = (int)$params['configoptions']['ipv6_prefix'];
    }

    $wait_for_os_install = (bool)($params["configoption10"] != "on");

    $vm_create = $admin->post("vm/v3/host", $vm_create_params);

    \ISPsystem\wait(
        function () use ($admin, $vm_create) {
            return $admin->get("vm/v3/task?where=((consul_id+EQ+'" . $vm_create->{"task"} . "')+AND+(name+EQ+'host_create'))")->{"list"};
        },
        function ($task_list) use ($admin, $vm_create, $params, $wait_for_os_install) {
            if (sizeof($task_list) != 0) {
                $status = $task_list[0]->{"status"};

                if ($status != "fail") {
                    if ($wait_for_os_install && $status != "complete") {
                        return false;
                    }

                    vmmanager6_save_external_id($params, $vm_create->{"id"});
                    return true;
                }
            }

            throw new \ISPsystem\LogicError("VM creation fail");
        },
        5 * 60,
        12
    );

    $vm_info = vmmanager6_get_vm_info($admin, $vm_create->{"id"});

    $main_ip = "";
    $additional_ips = array();
    foreach ($vm_info->{"ip4"} as $ip) {
        if (empty($main_ip)) {
            $main_ip = $ip->{"ip"};
        } else {
            array_push($additional_ips, $ip->{"ip"});
        }
    }

    foreach ($vm_info->{"ip6"} as $ip) {
        if (empty($main_ip)) {
            $main_ip = $ip->{"ip"};
        } else {
            array_push($additional_ips, $ip->{"ip"});
        }
    }

    DB::table('tblhosting')
        ->where('id', $params["serviceid"])
        ->update(
            [
                'dedicatedip' => $main_ip,
                'assignedips' => implode("\n", $additional_ips),
            ]
        );
}

function _vmmanager6_suspend_account(array $params)
{
    $admin = vmmanager6_get_admin($params);
    $vm_id = vmmanager6_get_external_id($params);

    $vm_stop = $admin->post("vm/v3/host/" . $vm_id . "/stop", (object)array());

    \ISPsystem\wait(
        function () use ($admin, $vm_stop) {
            return $admin->get("vm/v3/task?where=((consul_id+EQ+'" . $vm_stop->{"task"} . "')+AND+(name+EQ+'host_stop'))")->{"list"};
        },
        function ($task_list) use ($admin, $params) {
            if (sizeof($task_list) != 0) {
                $status = $task_list[0]->{"status"};

                if ($status == "complete") {
                    return true;
                } elseif ($status != "fail") {
                    return false;
                }
            }

            throw new \ISPsystem\LogicError("VM suspend fail");
        },
        5 * 60,
        12
    );
}

function _vmmanager6_unsuspend_account(array $params)
{
    $admin = vmmanager6_get_admin($params);
    $vm_id = vmmanager6_get_external_id($params);

    $vm_start = $admin->post("vm/v3/host/" . $vm_id . "/start", (object)array());

    \ISPsystem\wait(
        function () use ($admin, $vm_start) {
            return $admin->get("vm/v3/task?where=((consul_id+EQ+'" . $vm_start->{"task"} . "')+AND+(name+EQ+'host_start'))")->{"list"};
        },
        function ($task_list) use ($admin, $params) {
            if (sizeof($task_list) != 0) {
                $status = $task_list[0]->{"status"};

                if ($status == "complete") {
                    return true;
                } elseif ($status != "fail") {
                    return false;
                }
            }

            throw new \ISPsystem\LogicError("VM unsuspend fail");
        },
        5 * 60,
        12
    );
}

function _vmmanager6_terminate_account(array $params)
{

    $admin = vmmanager6_get_admin($params);
    $vm_id = vmmanager6_get_external_id($params);

    $vm_delete = $admin->delete("vm/v3/host/" . $vm_id);

    \ISPsystem\wait(
        function () use ($admin, $vm_delete) {
            return $admin->get("vm/v3/task?where=((consul_id+EQ+'" . $vm_delete->{"task"} . "')+AND+(name+EQ+'host_delete'))")->{"list"};
        },
        function ($task_list) use ($admin, $params) {
            if (sizeof($task_list) != 0) {
                $status = $task_list[0]->{"status"};

                if ($status == "complete") {
                    return true;
                } elseif ($status != "fail") {
                    return false;
                }
            }

            throw new \ISPsystem\LogicError("VM terminate fail");
        },
        5 * 60,
        12
    );
}

function _vmmanager6_change_package(array $params)
{
    $admin = vmmanager6_get_admin($params);
    $vm_id = vmmanager6_get_external_id($params);
    $vm_info = vmmanager6_get_vm_info($admin, $vm_id);
    $vm_ipv6_info = $admin->get("vm/v3/host/{$vm_id}/ipv6");

    $is_active = (bool)($vm_info->{"state"} == "active");

    $disk = array(
        "id" => (int)$vm_info->{"disk"}->{"id"},
        "size_mib" => (int)$vm_info->{"disk"}->{"disk_mib"},
    );

    $cpu_number = (int)$vm_info->{"cpu_number"};
    $ram_mib = (int)$vm_info->{"ram_mib"};
    $net_bandwidth_mbitps = (int)$vm_info->{"net_bandwidth_mbitps"};
    $os_id = (int)$vm_info->{"os"}->{"id"};
    $ipv4_number = (int) count($vm_info->{"ip4"});
    $ipv6_enabled = (bool) $vm_ipv6_info->{"ipv6_enabled"};
    $ipv6_prefix = (int) $vm_ipv6_info->{"ipv6_prefix"};

    $cpu_number_new = (int)$params["configoption4"];
    $ram_mib_new = (int)$params["configoption5"];
    $hdd_mib_new = (int)$params["configoption6"];
    $net_bandwidth_mbitps_new = (int)$params["configoption7"];
    $os_id_new = null;
    if ($params['configoption2'] === 'os') {
        $os_id_new = (int) $params['configoption3'];
    }
    $ipv4_number_new = $ipv4_number;
    $ipv6_enabled_new = $ipv6_enabled;
    $ipv6_prefix_new = $ipv6_prefix;

    foreach ($params["configoptions"] as $key => $value) {
        if ($key === 'cpu_number') {
            $cpu_number_new = (int) $value;
        } elseif ($key === 'ram_mib') {
            $ram_mib_new = (int) $value;
        } elseif ($key === 'hdd_mib') {
            $hdd_mib_new = (int) $value;
        } elseif ($key === 'net_bandwidth_mbitps') {
            $net_bandwidth_mbitps_new = (int) $value;
        } elseif ($key === 'os') {
            $os_id_new = (int) $value;
        } elseif ($key === 'ipv4_number') {
            $ipv4_number_new = (int) $value;
        } elseif ($key === 'ipv6_enabled') {
            $ipv6_enabled_new = (bool) $value;
        } elseif ($key === 'ipv6_prefix') {
            $ipv6_prefix_new = (int) $value;
        }
    }

    $need_disk_resize = (bool)($disk["size_mib"] < $hdd_mib_new);
    $need_host_change = (bool)(($cpu_number != $cpu_number_new) || ($ram_mib != $ram_mib_new) || ($net_bandwidth_mbitps != $net_bandwidth_mbitps_new));
    $need_reinstall = (bool)(isset($os_id_new) && $os_id != $os_id_new);
    $need_ip_change = (bool)(($ipv4_number != $ipv4_number_new) || ($ipv6_enabled != $ipv6_enabled_new) || ($ipv6_prefix != $ipv6_prefix_new));

    if (!$need_disk_resize && !$need_host_change && !$need_reinstall && !$need_ip_change) {
        return;
    }

    $query_params = "";

    if ($need_disk_resize) {
        $disk_resize_params = array("size_mib" => $hdd_mib_new);
        if ($is_active) {
            $disk_resize_params["defer"] = array(
                "action" => "host_stop",
            );
        }

        $disk_resize = $admin->post("vm/v3/disk/" . $disk["id"], $disk_resize_params);
        $query_params .= "((consul_id+EQ+'" . $disk_resize->{"task"} . "')+AND+(name+EQ+'disk_resize'))";
    }

    if ($need_host_change) {
        $host_change_params = array(
            "cpu_number" => $cpu_number_new,
            "ram_mib" => $ram_mib_new,
            "net_bandwidth_mbitps" => $net_bandwidth_mbitps_new,
        );
        if ($is_active) {
            $host_change_params["defer"] = array(
                "action" => "host_stop",
            );
        }

        $host_change = $admin->post("vm/v3/host/" . $vm_id . "/resource", $host_change_params);
        if ($query_params) {
            $query_params .= "+OR+";
        }
        $query_params .= "((consul_id+EQ+'" . $host_change->{"task"} . "')+AND+(name+EQ+'host_change_params'))";
    }

    if ($need_ip_change) {
        $ip_change_params = [];
        if ($ipv4_number != $ipv4_number_new) {
            $ip_change_params['ipv4_number'] = $ipv4_number_new - $ipv4_number;
            $ip_change_params['ipv4_pool'] = [(int)$params["configoption8"]];
        }
        if ($ipv6_enabled != $ipv6_enabled_new) {
            $ip_change_params['ipv6_enabled'] = $ipv6_enabled_new;
        }
        if ($ipv6_prefix != $ipv6_prefix_new && $ipv6_enabled_new) {
            $ip_change_params['ipv6_prefix'] = $ipv6_prefix_new;
            $ip_change_params['ipv6_enabled'] = true;
        }
        $ip_change = $admin->post("vm/v3/host/" . $vm_id . "/ip", $ip_change_params);
        if ($query_params) {
            $query_params .= "+OR+";
        }
        $query_params .= "((consul_id+EQ+'" . $ip_change->{"task"} . "')+AND+(name+EQ+'ip_change'))";
    }

    if ($is_active && !$need_reinstall) {
        $host_restart = $admin->post("vm/v3/host/" . $vm_id . "/restart", (object)array());
        if ($query_params) {
            $query_params .= "+OR+";
        }
        $query_params .= "((consul_id+EQ+'" . $host_restart->{"start_task"} . "')+AND+(name+EQ+'host_start'))";
    }

    if ($need_reinstall) {
        $reinstall_params = ['os' => $os_id_new];
        $reinstall = $admin->post("vm/v3/host/" . $vm_id . "/reinstall", $reinstall_params);
        if ($query_params) {
            $query_params .= "+OR+";
        }
        $query_params .= "((consul_id+EQ+'" . $reinstall->{"task"} . "')+AND+(name+EQ+'host_reinstall'))";
    }

    $query_params = '?where=' . $query_params;

    \ISPsystem\wait(
        function () use ($admin, $query_params) {
            return $admin->get("vm/v3/task" . $query_params)->{"list"};
        },
        function ($task_list) use ($admin, $params) {
            if (sizeof($task_list) != 0) {
                foreach ($task_list as $key => $value) {
                    if ($value->{"status"} == "fail") {
                        throw new \ISPsystem\LogicError("VM change package fail");
                    } elseif ($value->{"status"} != "complete") {
                        return false;
                    }
                }
                return true;
            }

            throw new \ISPsystem\LogicError("VM change package fail");
        },
        5 * 60,
        12
    );
}

function _vmmanager6_change_password(array $params)
{
    $admin = vmmanager6_get_admin($params);
    $vm_id = vmmanager6_get_external_id($params);

    $host_change_params = [
        'password' => $params['password'],
    ];

    $admin->post("vm/v3/host/" . $vm_id . "/password", $host_change_params);
}

function vmmanager6_get_sso_redirect_address($params, $email = null) // phpcs:ignore
{
    vmmanager6_load_ispsystem_libs();
    $addr = isset($params["serverhostname"]) && strlen($params["serverhostname"]) > 0
          ? $params["serverhostname"]
          : $params["serverip"];
    if (!isset($email)) {
        $email = $params["serverusername"];
    }

    $prefix = isset($params["serverhttpprefix"]) && strlen($params["serverhttpprefix"]) > 0
          ? $params["serverhttpprefix"]
          : "http";

    $addr = isset($params["serverport"]) && strlen($params["serverport"]) > 0
          ? $prefix . "://" . $addr . ":" . $params["serverport"]
          : $prefix . "://" . $addr;

    $admin = vmmanager6_get_admin($params);

    $key_response = $admin->post("auth/v4/user/{$email}/key", (object)array());
    $key = (string)($key_response->{"key"});
    if (strlen($key) == 0) {
        throw new ISPSystem\LogicError("can not get auth key");
    }

    return "{$addr}/auth/key-v4/{$key}";
}

function vmmanager6_CreateAccount(array $params)
{
    vmmanager6_load_ispsystem_libs();
    return \ISPsystem\safeCall("_vmmanager6_create_account", $params, __FUNCTION__);
}

function vmmanager6_SuspendAccount(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    return \ISPsystem\safeCall("_vmmanager6_suspend_account", $params, __FUNCTION__);
}

function vmmanager6_UnsuspendAccount(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    return \ISPsystem\safeCall("_vmmanager6_unsuspend_account", $params, __FUNCTION__);
}

function vmmanager6_TerminateAccount(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    return \ISPsystem\safeCall("_vmmanager6_terminate_account", $params, __FUNCTION__);
}

function vmmanager6_ChangePackage(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    return \ISPsystem\safeCall("_vmmanager6_change_package", $params, __FUNCTION__);
}

function vmmanager6_ChangePassword(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    return \ISPsystem\safeCall("_vmmanager6_change_password", $params, __FUNCTION__);
}

function vmmanager6_AdminSingleSignOn(array $params) // phpcs:ignore
{
    $sso_redirect_address = vmmanager6_get_sso_redirect_address($params);
    return [
        "success" => true,
        "redirectTo" => $sso_redirect_address,
    ];
}

function vmmanager6_ServiceSingleSignOn(array $params) // phpcs:ignore
{
    $admin = vmmanager6_get_admin($params);
    try {
        $user_email = vmmanager6_get_user_mail($params);
    } catch (Exception $e) {
        return [
            "success" => false,
            "errorMsg" => $e->getMessage(),
        ];
    }

    $user_info = $admin->get("vm/v3/account?where=(email+EQ+'" . $user_email . "')")->{"list"};
    if (sizeof($user_info) != 0) {
        if (vmmanager6_user_has_role($user_info[0]->{"roles"}, "@admin", false)) {
            return [
                "success" => false,
                "errorMsg" => "Administrator users must login from the administrator interface",
            ];
        }

        $sso_redirect_address = vmmanager6_get_sso_redirect_address($params, $user_email);
        return [
            "success" => true,
            "redirectTo" => $sso_redirect_address,
        ];
    }

    return [
        "success" => false,
        "errorMsg" => "User not found",
    ];
}

function vmmanager6_ClientAreaCustomButtonArray() // phpcs:ignore
{
    return [
        "Reboot Server" => "RebootAsUser",
        "Power off Server" => "PowerOffAsUser",
        "Power on Server" => "PowerOnAsUser"
    ];
}

function vmmanager6_ClientArea(array $params)
{
    $show_statistic = (bool)($params['configoption12'] === 'on');
    $allow_change_ptr = (bool)($params['configoption13'] === 'on');

    if (!empty($_GET['statistic'])) {
        $stat_data = [];
        $vm_info = [];
        if ($show_statistic) {
            try {
                $admin = vmmanager6_get_admin($params);
                $vm_id = vmmanager6_get_external_id($params);
                $from = date('H:i_Ymd', strtotime('-1 day'));

                $vm_info = vmmanager6_get_vm_info($admin, $vm_id);

                $metrics = ['cpu', 'memory', 'storage', 'net_rx', 'net_tx'];

                foreach ($metrics as $key) {
                    if ($key === 'cpu') {
                        $data = $admin->get('vm/v3/host/' . $vm_id . '/metrics?target=cpu_load&from=' . $from);
                    } elseif ($key === 'memory') {
                        $data = $admin->get('vm/v3/host/' . $vm_id . '/metrics?target=mem_usage&from=' . $from);
                    } elseif ($key === 'storage') {
                        $data = $admin->get('vm/v3/host/' . $vm_id . '/metrics?target=df.root.used&from=' . $from);
                    } else {
                        $data = $admin->get('vm/v3/host/' . $vm_id . '/metrics?target=' . $key . '&from=' . $from);
                    }
                    if (sizeof($data) == 0) {
                        throw new \ISPsystem\LogicError("Empty host metrics data");
                    }
                    if (!empty($data[0]->datapoints)) {
                        $stat_data[$key] = json_encode($data[0]->datapoints, JSON_UNESCAPED_UNICODE);
                    } else {
                        $stat_data[$key] = '[]';
                    }
                }
            } catch (Exception $e) {
            }
        }

        return array(
            'tabOverviewReplacementTemplate' => 'statistic.tpl',
            'templateVariables' => array('show_statistic' => $show_statistic, 'statistic' => $stat_data, 'vm_info' => json_encode($vm_info, JSON_UNESCAPED_UNICODE)),
        );
    } elseif (!empty($_GET['ip'])) {
        $ip_list = [];
        if ($allow_change_ptr) {
            try {
                $admin = vmmanager6_get_admin($params);
                $vm_id = vmmanager6_get_external_id($params);

                $ip_list = $admin->get("vm/v3/host/" . $vm_id . "/ipv4")->{'list'};
                //$admin->post("vm/v3/ip/1/ptr", ['domain' => 'myexample.com']);
            } catch (Exception $e) {
            }
        }
        return array(
            'tabOverviewReplacementTemplate' => 'ip.tpl',
            'templateVariables' => array('allow_change_ptr' => $allow_change_ptr, 'ip_list' => $ip_list)
        );
    } elseif (!empty($_GET['ip_change'])) {
        $ip_info = [];
        $updated = false;
        if ($allow_change_ptr) {
            try {
                $admin = vmmanager6_get_admin($params);
                $vm_id = vmmanager6_get_external_id($params);

                $ip_id = (int) $_GET['ip_id'];
                $ip_info = $admin->get("vm/v3/host/" . $vm_id . "/ipv4?where=(id+EQ+'" . $ip_id . "')")->{'list'};
                $ip_info = $ip_info[0];

                if (!empty($_POST['domain']) && !empty($ip_info)) {
                    $new_domain = htmlspecialchars(strip_tags($_POST['domain']));
                    if ($new_domain !== $ip_info->domain) {
                        $admin->post("vm/v3/ip/" . $ip_id . "/ptr", ['domain' => $new_domain]);
                        $ip_info->domain = $new_domain;
                        $updated = true;
                    }
                }
            } catch (Exception $e) {
            }
        }
        return array(
            'tabOverviewReplacementTemplate' => 'ip_change.tpl',
            'templateVariables' => array('allow_change_ptr' => $allow_change_ptr, 'ip_info' => $ip_info, 'updated' => $updated)
        );
    }

    return array(
        'tabOverviewModuleOutputTemplate' => 'clientarea.tpl',
        'templateVariables' => array('show_statistic' => $show_statistic, 'allow_change_ptr' => $allow_change_ptr),
    );
}

function vmmanager6_AdminCustomButtonArray() // phpcs:ignore
{
    return [
        "Reboot Server" => "Reboot",
        "Power off Server" => "PowerOff",
        "Power on Server" => "PowerOn",
    ];
}

function vmmanager6_Reboot(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    $params["host_action"] = "restart";
    return \ISPsystem\safeCall("\ISPsystem\VM_API::host_action_as_admin", $params, __FUNCTION__);
}

function vmmanager6_RebootAsUser(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    $params["host_action"] = "restart";
    return \ISPsystem\safeCall("\ISPsystem\VM_API::host_action_as_user", $params, __FUNCTION__);
}

function vmmanager6_PowerOff(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    $params["host_action"] = "stop";
    return \ISPsystem\safeCall("\ISPsystem\VM_API::host_action_as_admin", $params, __FUNCTION__);
}

function vmmanager6_PowerOffAsUser(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    $params["host_action"] = "stop";
    return \ISPsystem\safeCall("\ISPsystem\VM_API::host_action_as_user", $params, __FUNCTION__);
}

function vmmanager6_PowerOn(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    $params["host_action"] = "start";
    return \ISPsystem\safeCall("\ISPsystem\VM_API::host_action_as_admin", $params, __FUNCTION__);
}

function vmmanager6_PowerOnAsUser(array $params)
{
    if ($params['configoption14'] === 'on') {
        return 'success';
    }
    vmmanager6_load_ispsystem_libs();
    $params["host_action"] = "start";
    return \ISPsystem\safeCall("\ISPsystem\VM_API::host_action_as_user", $params, __FUNCTION__);
}

function vmmanager6_MetricProvider($params)
{
    return new VMMetricProvider($params);
}
