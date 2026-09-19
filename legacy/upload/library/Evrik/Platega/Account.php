<?php

class Evrik_Platega_Account extends XFCP_Evrik_Platega_Account
{
    public function actionPlatega()
    {
        try { $service = new Evrik_Platega_Service(); }
        catch (Throwable $e) { return $this->responseError('Оплата Platega не настроена.'); }
        $user = XenForo_Visitor::getInstance()->toArray();
        if ($this->_request->isPost())
        {
            $this->_assertPostOnly();
            try
            {
                $url = $service->initiate($user, $this->_input->filterSingle('user_upgrade_id', XenForo_Input::UINT),
                    XenForo_Link::buildPublicLink('full:account/upgrades'));
                return $this->responseRedirect(XenForo_ControllerResponse_Redirect::RESOURCE_CANONICAL, $url);
            }
            catch (Throwable $e)
            {
                return $this->responseError('Платёж не создан. Проверьте настройки и журнал операций Platega перед повторной оплатой.');
            }
        }
        $model = $this->getModelFromCache('XenForo_Model_UserUpgrade');
        $list = $model->getUserUpgradesForPurchaseList($user);
        $available = array_filter($list['available'], function ($upgrade) {
            return strtoupper($upgrade['cost_currency']) === 'RUB' && !$upgrade['recurring'];
        });
        return $this->_getWrapper('account', 'upgrades',
            $this->responseView('', 'evrik_platega_legacy', ['available' => $model->prepareUserUpgrades($available)]));
    }
}
