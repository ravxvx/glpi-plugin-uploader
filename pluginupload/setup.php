<?php

/**
 * Plugin Uploader - GLPI 11 / 12
 * Install or update GLPI plugins by uploading a .zip / .tar.gz / .tgz file in the browser.
 * Only users with "Setup > General" update rights (Super-Admin) can use it.
 * Settings page: Setup > Plugins > Plugin Uploader (wrench icon).
 */

define('PLUGIN_PLUGINUPLOAD_VERSION', '1.0.2');

function plugin_init_pluginupload()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['pluginupload'] = true;

    if (!Plugin::isPluginActive('pluginupload')) {
        return;
    }

    $PLUGIN_HOOKS['config_page']['pluginupload'] = 'front/upload.php';
}

function plugin_version_pluginupload()
{
    return [
        'name'         => 'Plugin Uploader',
        'version'      => PLUGIN_PLUGINUPLOAD_VERSION,
        'author'       => 'School IT',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '12.99.99',
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_pluginupload_check_prerequisites()
{
    return true;
}

function plugin_pluginupload_check_config($verbose = false)
{
    return true;
}
