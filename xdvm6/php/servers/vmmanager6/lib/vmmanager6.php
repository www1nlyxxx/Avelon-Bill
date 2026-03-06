<?php

/**
 * VMmanager 6 integration module
 *
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright Copyright (c) ISPsystem LLC 2019
 * @link      http://www.ispsystem.com/
 */

namespace ISPsystem;

use WHMCS\Database\Capsule as DB;

//TODO (d.syrovatskiy) чтобы не плодить кучу ф-ий в основном файле нужно переехать сюда

// Продублировал логику get_admin/get_external_id в этом классе
// T.к. safeCall не возвращает результат ф-ии
class VM_API // phpcs:ignore
{
    public static function check_email_verifying(array &$params) // phpcs:ignore
    {
        $check_email_verifing_option = (bool)($params["configoption11"] == "on");
        if (!$check_email_verifing_option) {
            return;
        }

        try {
            $is_user_email_verified = (bool)$params["model"]["relations"]["client"]["attributes"]["email_verified"];
            if (!$is_user_email_verified) {
                throw new \ISPsystem\LogicError("User email is not verified");
            }
        } catch (Exception $e) {
            throw new \ISPsystem\LogicError("Email verify field not found: " . $e->getMessage());
        }
    }

    public static function get_admin(&$params) // phpcs:ignore
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

    public static function get_user(&$params) // phpcs:ignore
    {
        self::check_email_verifying($params);

        $admin = self::get_admin($params);
        $user = \ISPsystem\AuthV4::user(
            $admin,
            $params["clientsdetails"]["email"]
        );
        return $user;
    }

    public static function get_external_id($params) // phpcs:ignore
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

    public static function host_action_as_admin(array &$params) // phpcs:ignore
    {
        $admin = self::get_admin($params);
        $server_id = self::get_external_id($params);
        self::m_host_action($server_id, $admin, $params["host_action"]);
    }

    public static function host_action_as_user(array &$params) // phpcs:ignore
    {
        $user = self::get_user($params);
        $server_id = self::get_external_id($params);
        self::m_host_action($server_id, $user, $params["host_action"]);
    }

    private static function m_host_action(int $server_id, &$account, string $action) // phpcs:ignore
    {
        $account->post("vm/v3/host/$server_id/$action", (object)array());
        return;
    }
}
