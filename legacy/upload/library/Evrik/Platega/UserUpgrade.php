<?php

class Evrik_Platega_UserUpgrade extends XFCP_Evrik_Platega_UserUpgrade
{
    protected function _getFields()
    {
        $fields = parent::_getFields();
        $fields['xf_user_upgrade']['cost_currency']['allowedValues'][] = 'rub';
        return $fields;
    }
}
