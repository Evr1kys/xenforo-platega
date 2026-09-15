<?php

namespace Evrik\Platega\Admin\Controller;

use Evrik\Platega\Payment\Platega;
use XF\Mvc\ParameterBag;

class Payment extends \XF\Admin\Controller\AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('payment');
    }

    public function actionIndex()
    {
        $page = max(1, $this->filterPage());
        $perPage = 30;
        $search = trim($this->filter('search', 'str'));
        $where = '';
        $bind = [];
        if ($search !== '')
        {
            $where = ' WHERE request_key = ? OR transaction_id = ?';
            $bind = [$search, $search];
        }
        $total = (int)\XF::db()->fetchOne('SELECT COUNT(*) FROM ' . Platega::TABLE . $where, $bind);
        $page = min($page, max(1, (int)ceil($total / $perPage)));
        $invoices = \XF::db()->fetchAll('SELECT * FROM ' . Platega::TABLE . $where
            . ' ORDER BY created_date DESC, request_key LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $bind);
        $profiles = $this->finder('XF:PaymentProfile')->where('provider_id', 'evrikPlatega')->fetch();
        return $this->view('Evrik\Platega:Payment\Listing', 'evrik_platega_payments', [
            'invoices' => $invoices, 'profiles' => $profiles,
            'page' => $page, 'perPage' => $perPage, 'total' => $total, 'search' => $search
        ]);
    }

    protected function provider()
    {
        return new Platega('evrikPlatega');
    }

    public function actionCheck()
    {
        $this->assertPostOnly();
        try
        {
            $state = $this->provider()->reconcile(
                trim($this->filter('request_key', 'str')),
                trim($this->filter('transaction_id', 'str')) ?: null
            );
        }
        catch (\Throwable $e)
        {
            return $this->error(\XF::phrase('evrik_platega_manual_failed'));
        }
        return $this->view('Evrik\Platega:Payment\Result', 'evrik_platega_result', [
            'requestKey' => $state->requestKey,
            'remoteStatus' => $state->remote['status'] ?? '',
            'message' => $state->logMessage,
            'failed' => (bool)$state->httpCode
        ]);
    }

    public function actionConnection()
    {
        $this->assertPostOnly();
        $profile = $this->em()->find('XF:PaymentProfile', $this->filter('payment_profile_id', 'uint'));
        if (!$profile || $profile->provider_id !== 'evrikPlatega')
        {
            return $this->notFound();
        }
        try
        {
            $this->provider()->checkConnection($profile);
        }
        catch (\Throwable $e)
        {
            return $this->error(\XF::phrase('evrik_platega_connection_failed'));
        }
        return $this->redirect($this->buildLink('evrik-platega'), \XF::phrase('evrik_platega_connection_ok'));
    }
}
