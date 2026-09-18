<?php

namespace Evrik\Platega\Admin\Controller;

use Evrik\Platega\Api\Protocol;
use Evrik\Platega\Payment\Platega;
use XF\Mvc\ParameterBag;

class Payment extends \XF\Admin\Controller\AbstractController
{
    protected static $statuses = [
        'CREATING', 'UNCERTAIN', 'PENDING', 'CONFIRMED', 'CANCELED', 'CHARGEBACKED'
    ];

    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('payment');
    }

    public function actionIndex()
    {
        $page = max(1, $this->filterPage());
        $perPage = 30;
        $search = trim($this->filter('search', 'str'));
        $status = strtoupper(trim($this->filter('status', 'str')));
        $profileId = $this->filter('profile', 'uint');
        if (!in_array($status, self::$statuses, true))
        {
            $status = '';
        }

        $where = [];
        $bind = [];
        if ($search !== '')
        {
            $where[] = '(request_key = ? OR transaction_id = ?)';
            $bind[] = $search;
            $bind[] = $search;
        }
        if ($status !== '')
        {
            $where[] = 'status = ?';
            $bind[] = $status;
        }
        if ($profileId)
        {
            $where[] = 'payment_profile_id = ?';
            $bind[] = $profileId;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $db = \XF::db();
        $total = (int)$db->fetchOne('SELECT COUNT(*) FROM ' . Platega::TABLE . $whereSql, $bind);
        $page = min($page, max(1, (int)ceil($total / $perPage)));
        $invoices = $db->fetchAll('SELECT * FROM ' . Platega::TABLE . $whereSql
            . ' ORDER BY created_date DESC, request_key LIMIT ' . $perPage
            . ' OFFSET ' . (($page - 1) * $perPage), $bind);

        $profiles = $this->finder('XF:PaymentProfile')->where('provider_id', 'evrikPlatega')->fetch();
        $profileTitles = [];
        foreach ($profiles as $profile)
        {
            $profileTitles[$profile->payment_profile_id] = $profile->title;
        }
        foreach ($invoices as &$invoice)
        {
            $invoice['profile_title'] = $profileTitles[$invoice['payment_profile_id']]
                ?? ('#' . $invoice['payment_profile_id']);
        }
        unset($invoice);

        $summary = [];
        foreach (self::$statuses as $knownStatus)
        {
            $summary[$knownStatus] = 0;
        }
        foreach ($db->fetchAll('SELECT status, COUNT(*) AS total FROM ' . Platega::TABLE . ' GROUP BY status') as $row)
        {
            $summary[$row['status']] = (int)$row['total'];
        }

        $manualRequestKey = trim($this->filter('request_key', 'str'));
        if (!preg_match('/\A[a-zA-Z0-9]{32}\z/', $manualRequestKey))
        {
            $manualRequestKey = '';
        }
        $manualTransactionId = trim($this->filter('transaction_id', 'str'));
        if (!Protocol::uuid($manualTransactionId))
        {
            $manualTransactionId = '';
        }

        return $this->view('Evrik\Platega:Payment\Listing', 'evrik_platega_payments', [
            'invoices' => $invoices,
            'profiles' => $profiles,
            'summary' => $summary,
            'statuses' => self::$statuses,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'search' => $search,
            'status' => $status,
            'profileId' => $profileId,
            'manualRequestKey' => $manualRequestKey,
            'manualTransactionId' => $manualTransactionId
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
