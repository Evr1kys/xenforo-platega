<?php

class Evrik_Platega_Setup
{
    public static function install()
    {
        if (PHP_VERSION_ID < 70400 || XenForo_Application::$versionId < 1050070
            || XenForo_Application::$versionId >= 2000000 || !extension_loaded('curl'))
        {
            throw new XenForo_Exception('Platega requires XenForo 1.5, PHP 7.4 and cURL.', true);
        }
        XenForo_Application::getDb()->query("CREATE TABLE IF NOT EXISTS xf_evrik_platega_legacy (
            request_key VARBINARY(32) PRIMARY KEY,
            transaction_id VARCHAR(36) NULL,
            user_id INT UNSIGNED NOT NULL,
            user_upgrade_id INT UNSIGNED NOT NULL,
            amount_minor BIGINT UNSIGNED NOT NULL,
            currency VARCHAR(3) NOT NULL,
            status VARCHAR(20) NOT NULL,
            redirect_url TEXT NULL,
            upgrade_snapshot TEXT NOT NULL,
            record_id INT UNSIGNED NOT NULL DEFAULT 0,
            record_end INT UNSIGNED NOT NULL DEFAULT 0,
            created_date INT UNSIGNED NOT NULL,
            updated_date INT UNSIGNED NOT NULL,
            UNIQUE KEY transaction_id (transaction_id),
            KEY purchase (user_id, user_upgrade_id, created_date)
        ) ENGINE=InnoDB");
    }

    public static function uninstall()
    {
        XenForo_Application::getDb()->query('DROP TABLE IF EXISTS xf_evrik_platega_legacy');
    }

    public static function loadAccount($class, array &$extend)
    {
        if ($class === 'XenForo_DataWriter_UserUpgrade')
        {
            $extend[] = 'Evrik_Platega_UserUpgrade';
        }
        if ($class === 'XenForo_ControllerPublic_Account')
        {
            $extend[] = 'Evrik_Platega_Account';
        }
    }
}
