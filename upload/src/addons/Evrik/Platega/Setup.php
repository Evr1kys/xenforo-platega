<?php

namespace Evrik\Platega;

use XF\Db\Schema\Create;

class Setup extends \XF\AddOn\AbstractSetup
{
    public function install(array $stepParams = [])
    {
        $this->schemaManager()->createTable('xf_evrik_platega_invoice', function (Create $table)
        {
            $table->addColumn('request_key', 'varbinary', 32);
            $table->addColumn('transaction_id', 'varchar', 36)->nullable();
            $table->addColumn('payment_profile_id', 'int')->unsigned();
            $table->addColumn('amount_minor', 'bigint')->unsigned();
            $table->addColumn('currency', 'varchar', 3);
            $table->addColumn('redirect_url', 'text')->nullable();
            $table->addColumn('status', 'varchar', 20)->setDefault('CREATING');
            $table->addColumn('created_date', 'int')->unsigned();
            $table->addColumn('updated_date', 'int')->unsigned();
            $table->addPrimaryKey('request_key');
            $table->addUniqueKey('transaction_id');
        });
        $this->registerProvider();
    }

    public function upgrade(array $stepParams = [])
    {
        $this->registerProvider();
    }

    public function postRebuild()
    {
        $this->registerProvider();
    }

    protected function registerProvider()
    {
        \XF::db()->insert('xf_payment_provider', [
            'provider_id' => 'evrikPlatega',
            'provider_class' => 'Evrik\\Platega:Platega',
            'addon_id' => 'Evrik/Platega'
        ], false, 'provider_class = VALUES(provider_class), addon_id = VALUES(addon_id)');
    }

    public function uninstall(array $stepParams = [])
    {
        \XF::db()->delete('xf_payment_provider', 'provider_id = ?', 'evrikPlatega');
        $this->schemaManager()->dropTable('xf_evrik_platega_invoice');
    }
}
