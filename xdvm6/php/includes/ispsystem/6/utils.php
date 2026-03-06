<?php

/**
 * ISPsystem additional common utils
 *
 * PHP version 5
 *
 * @category  WHMCS_Plugins
 * @package   ISPsystem
 * @author    ISPsystem LLC <support@ispsystem.com>
 * @copyright 2019 ISPsystem LLC
 * @license   Proprietary http://www.ispsystem.com/
 * @link      http://www.ispsystem.com/
 */

namespace ISPsystem;

use WHMCS\Database\Capsule as DB;

/**
 * Wait success result of function call or throw exception
 *
 * @param function $func    - anonymous function for execute
 * @param function $check   - anonymous function for checking result of $func
 * @param int      $timeout - timeout for getting success result of $func
 * @param int      $period  - timeout period
 *
 * @return void
 */
function wait($func, $check, $timeout = 60, $period = 1)
{
    $start = time();
    do {
        if ($check($func())) {
            return;
        }
    } while ((time() - $start) < $timeout && sleep($period) === 0);
    throw new \Exception("operation timeout: function waited for {$timeout}s");
}

/**
 * Checks server availability
 *
 * @param string $host    - server ip or hostname
 * @param int    $port    - port number
 * @param int    $timeout - waiting timeout
 *
 * @return void
 */
function ping($host, $port = 22, $timeout = 60)
{
    wait(
        function () use ($host, $port) {
            $timeout = 15;
            $fp = fsockopen($host, $port, $errCode, $errStr, $timeout);
            \logActivity(
                "Server ping result - [{$host}:{$port}]: {$errCode}: '{$errStr}'"
            );
            $result = (bool)$fp;
            fclose($fp);
            return $result;
        },
        function ($r) {
            return $r;
        },
        $timeout
    );
}

/**
 * Safely call module function and return result of executiong or error
 *
 * @param string $func            - function name
 * @param array  $args            - function arguments
 * @param string $log_func        - parent action name for logging
 * @param bool   $get_func_result - return func result to output
 *
 * @return string|array - "success" or error message
 */
function safeCall($func, $args, $log_func, $get_func_result = false)
{
    try {
        $r = call_user_func($func, $args);
        $r = $get_func_result
            ? $r
            : "success";
    } catch (APIError $e) {
        \logModuleCall(
            "safeCall::APIError",
            $log_func,
            $args,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        $r = $e->getMessage();
    } catch (LogicError $e) {
        \logModuleCall(
            "safeCall::LogicError",
            $log_func,
            $args,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        $r = $e->getMessage();
    } catch (\Exception $e) {
        \logModuleCall(
            "safeCall::Exception",
            $log_func,
            $args,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        $r = $e->getMessage();
    }
    return $r;
}

/**
 * Execute local WHMCS API function
 *
 * @param string $func  - function name
 * @param array  $param - function params
 *
 * @return array - function result
 */
function localApiCall($func, $param)
{
    if (!array_key_exists("ispsystem_whmcs_admin", $GLOBALS) || !$GLOBALS["ispsystem_whmcs_admin"]) {
        $admin = DB::table('tbladmins')
        ->leftJoin(
            'tbladminperms',
            'tbladmins.roleid',
            '=',
            'tbladminperms.roleid'
        )
        ->where(
            [
                ['tbladmins.disabled', '=', 0],
                ['tbladminperms.permid', '=', 81],
            ]
        )
        ->select('tbladmins.username')->first();
        if (empty($admin)) {
            throw new \Exception("admin user not found");
        }
        $GLOBALS["ispsystem_whmcs_admin"] = $admin->username;
    }
    return \localAPI($func, $param, $GLOBALS["ispsystem_whmcs_admin"]);
}


/**
 * Encrypt password
 *
 * @param string $password - password string
 *
 * @return string - encrypted password
 */
function encryptPassword($password)
{
    $pass_data = \ISPsystem\localApiCall(
        "EncryptPassword",
        array("password2" => $password)
    );
    return $pass_data['result'] === 'success' ? $pass_data['password'] : "";
}

/**
 * Decrypt password
 *
 * @param string $password - encrypted password
 *
 * @return string - password string
 */
function decryptPassword($password)
{
    $pass_data = \ISPsystem\localApiCall(
        "DecryptPassword",
        array("password2" => $password)
    );
    return $pass_data['result'] === 'success' ? $pass_data['password'] : "";
}

/**
 * Get random string
 *
 * @param int $length - string length
 *
 * @return string - generated string
 */
function randomString($length = 16)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return strtolower($randomString);
}
