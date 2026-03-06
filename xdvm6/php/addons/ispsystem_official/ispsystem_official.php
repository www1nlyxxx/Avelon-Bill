<?php

 /**
  * ISPsystem official addon
  *
  * PHP version 5
  *
  * @category  WHMCS_Plugins
  * @package   ISPsystem
  * @author    ISPsystem LLC <support@ispsystem.com>
  * @copyright 2014 ISPsystem LLC
  * @license   Proprietary http://www.ispsystem.com/
  * @link      http://www.ispsystem.com/
  */

use WHMCS\Database\Capsule as DB;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * ISPsystem official addon config
 *
 * @return array
 */
function ispsystem_official_config() { // phpcs:ignore
    $configarray = array(
                "name" => "ISPsystem global module",
                "description" =>
                    "Common module for integration with ISPsystem control panels",
                "version" => "1.2",
                "author" => "ISPsystem LLC",
                "language" => "english",
                "fields" => array(),
        );
    return $configarray;
}

/**
 * ISPsystem official addon activation function
 *
 * @return array
 */
function ispsystem_official_activate() { // phpcs:ignore
    if (!DB::schema()->hasTable('mod_ispsystem')) {
        try {
            DB::schema()->create(
                'mod_ispsystem',
                function ($table) {
                    $table->increments('id');
                    $table->integer('serviceid');
                    $table->integer('external_id');
                    $table->string('label')->nullable();
                    $table->string('ipmi_ip')->nullable();
                    $table->string('switch_port')->nullable();
                    $table->string('mac')->nullable();
                }
            );
        } catch (Exception $e) {
            return ['status' => 'error','description' => $e->getMessage()];
        }
    } else {
        try {
            ispsystem_official_upgrade(['version' => 1.0]);
        } catch (Exception $e) {
            LogActivity($e->getMessage());
        }
        try {
            ispsystem_official_upgrade(['version' => 1.1]);
        } catch (Exception $e) {
            LogActivity($e->getMessage());
        }
    }

    return ['status' => 'success','description' => 'Module actevated'];
}

/**
 * ISPsystem official addon deactivation function
 *
 * @return array
 */
function ispsystem_official_deactivate() { // phpcs:ignore
    return ['status' => 'success','description' => 'Module deactevated'];
}

/**
 * ISPsystem official addon deactivation function
 *
 * @param array $vars - upgrade arguments
 *
 * @return void
 */
function ispsystem_official_upgrade($vars) { // phpcs:ignore
    $version = $vars['version'];

    if ($version < 1.1) {
        DB::schema()->table(
            'mod_ispsystem',
            function ($table) {
                $table->string('label')->nullable();
                $table->string('ipmi_ip')->nullable();
                $table->string('switch_port')->nullable();
            }
        );
    }

    if ($version < 1.2) {
        DB::schema()->table(
            'mod_ispsystem',
            function ($table) {
                $table->string('mac')->nullable();
            }
        );
    }
}
