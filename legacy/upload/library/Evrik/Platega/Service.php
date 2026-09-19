<?php

require_once __DIR__ . '/Protocol.php';

use Evrik\Platega\Api\Protocol;

class Evrik_Platega_Service
{
    const TABLE = 'xf_evrik_platega_legacy';
    private $db;
    private $client;
    private $options;

    public function __construct($client = null, array $options = null)
    {
        $this->db = XenForo_Application::getDb();
        if (!$this->db->fetchOne("SELECT active FROM xf_addon WHERE addon_id = 'EvrikPlategaLegacy'"))
        {
            throw new RuntimeException('Platega add-on is not enabled.');
        }
        if ($options === null)
        {
            $config = XenForo_Application::getConfig();
            $options = isset($config->evrikPlatega) ? $config->evrikPlatega->toArray() : [];
        }
        if (!Protocol::uuid($options['merchant_id'] ?? null)
            || !is_string($options['secret'] ?? null) || $options['secret'] === ''
            || strlen($options['secret']) > 512 || preg_match('/[\x00-\x20\x7f]/', $options['secret']))
        {
            throw new RuntimeException('Configure Platega merchant_id and secret in library/config.php.');
        }
        $this->options = $options;
        $this->client = $client ?: new Evrik_Platega_Client($options);
    }

    public function initiate(array $user, $upgradeId, $returnUrl)
    {
        if (empty($user['user_id']) || $user['user_state'] !== 'valid' || !empty($user['is_banned']))
        {
            throw new RuntimeException('User cannot purchase upgrades.');
        }
        Protocol::redirectUrl($returnUrl);
        $db = $this->db;
        $lock = 'platega1:' . $user['user_id'] . ':' . (int)$upgradeId;
        if (!$db->fetchOne('SELECT GET_LOCK(?, 0)', $lock)) { throw new RuntimeException('Purchase is busy.'); }
        try
        {
            $model = XenForo_Model::create('XenForo_Model_UserUpgrade');
            $list = $model->getUserUpgradesForPurchaseList($user);
            $upgrade = $list['available'][$upgradeId] ?? null;
            if (!$upgrade || strtoupper($upgrade['cost_currency']) !== 'RUB' || $upgrade['recurring'])
            {
                throw new RuntimeException('Only available, one-time RUB upgrades are supported.');
            }
            $old = $db->fetchRow('SELECT * FROM ' . self::TABLE
                . " WHERE user_id = ? AND user_upgrade_id = ? AND status IN ('CREATING','UNCERTAIN','PENDING')"
                . ' ORDER BY created_date DESC LIMIT 1', [$user['user_id'], $upgradeId]);
            if ($old)
            {
                if ($old['status'] === 'PENDING' && $old['redirect_url'])
                {
                    return Protocol::redirectUrl($old['redirect_url']);
                }
                throw new RuntimeException('Previous payment needs reconciliation. Do not create a second payment.');
            }
            $amount = Protocol::minorUnits($upgrade['cost_amount']);
            if ($amount <= 0) { throw new RuntimeException('Invalid amount.'); }
            $key = bin2hex(random_bytes(16));
            $db->insert(self::TABLE, [
                'request_key' => $key, 'user_id' => $user['user_id'], 'user_upgrade_id' => $upgradeId,
                'amount_minor' => $amount, 'currency' => 'RUB', 'status' => 'CREATING',
                'upgrade_snapshot' => json_encode($upgrade), 'created_date' => time(), 'updated_date' => time()
            ]);
            try
            {
                $remote = $this->client->request('POST', '/v2/transaction/process', [
                    'paymentDetails' => ['amount' => $amount / 100, 'currency' => 'RUB'],
                    'description' => $upgrade['title'], 'return' => $returnUrl, 'failedUrl' => $returnUrl,
                    'payload' => $key,
                    'metadata' => ['userId' => (string)$user['user_id'], 'userName' => $user['username']]
                ]);
                $result = Protocol::createResult($remote);
                $db->update(self::TABLE, $result + ['status' => 'PENDING', 'updated_date' => time()],
                    $db->quoteInto('request_key = ?', $key));
                return $result['redirect_url'];
            }
            catch (Throwable $e)
            {
                $db->update(self::TABLE, ['status' => 'UNCERTAIN', 'updated_date' => time()],
                    $db->quoteInto('request_key = ?', $key));
                throw new RuntimeException('Payment needs review. Request key: ' . $key);
            }
        }
        finally { $db->fetchOne('SELECT RELEASE_LOCK(?)', $lock); }
    }

    public function callback(array $data, $merchant, $secret)
    {
        if (!Protocol::authenticated($merchant, $secret, $this->options))
        {
            throw new RuntimeException('Invalid callback authentication.');
        }
        if (!Protocol::uuid($data['id'] ?? null) || !is_string($data['payload'] ?? null))
        {
            throw new RuntimeException('Invalid callback.');
        }
        return $this->reconcile($data['payload'], $data['id']);
    }

    public function reconcile($key, $id = null)
    {
        if (!is_string($key) || !preg_match('/\A[a-f0-9]{32}\z/', $key))
        {
            throw new RuntimeException('Invalid request key.');
        }
        $db = $this->db;
        $invoice = $db->fetchRow('SELECT * FROM ' . self::TABLE . ' WHERE request_key = ?', $key);
        if (!$invoice) { throw new RuntimeException('Purchase not found.'); }
        if ($invoice['status'] === 'CREATING' && (int)$invoice['created_date'] > time() - 60)
        {
            throw new RuntimeException('Payment creation is still in progress. Retry the callback later.', 503);
        }
        $id = $id ?: $invoice['transaction_id'];
        if (!Protocol::uuid($id)) { throw new RuntimeException('Transaction ID required.'); }
        $id = strtolower($id);
        if ($invoice['transaction_id'] && $invoice['transaction_id'] !== $id)
        {
            throw new RuntimeException('Transaction mismatch.');
        }
        $remote = $this->client->request('GET', '/transaction/' . $id);
        $expected = $invoice;
        $expected['transaction_id'] = $id;
        Protocol::validateTransaction($remote, $expected);
        XenForo_Db::beginTransaction($db);
        try
        {
            $row = $db->fetchRow('SELECT * FROM ' . self::TABLE . ' WHERE request_key = ? FOR UPDATE', $key);
            if ($row['transaction_id'] && $row['transaction_id'] !== $id)
            {
                throw new RuntimeException('Transaction mismatch.');
            }
            $user = $db->fetchRow('SELECT * FROM xf_user WHERE user_id = ? FOR UPDATE', $row['user_id']);
            if (!$user) { throw new RuntimeException('User no longer exists.'); }
            $action = Protocol::action($row['status'], $remote['status']);
            $model = XenForo_Model::create('XenForo_Model_UserUpgrade');
            $update = ['transaction_id' => $id, 'updated_date' => time()];
            if ($action === 'receive')
            {
                $upgrade = $model->getUserUpgradeById($row['user_upgrade_id']);
                if (!$upgrade || $user['is_banned'] || $user['user_state'] !== 'valid'
                    || $model->getActiveUserUpgradeRecord($row['user_id'], $row['user_upgrade_id']))
                {
                    throw new RuntimeException('Upgrade requires manual review.');
                }
                $snapshot = json_decode($row['upgrade_snapshot'], true);
                if (!is_array($snapshot)) { throw new RuntimeException('Invalid purchase snapshot.'); }
                $recordId = $model->upgradeUser($row['user_id'], $snapshot, true);
                if (!$recordId) { throw new RuntimeException('Upgrade failed.'); }
                $record = $model->getActiveUserUpgradeRecordById($recordId);
                $update['record_id'] = $recordId;
                $update['record_end'] = $record['end_date'];
            }
            elseif ($action === 'reverse' && $row['record_id'])
            {
                $record = $model->getActiveUserUpgradeRecordById($row['record_id']);
                if ($record)
                {
                    if ((int)$record['end_date'] !== (int)$row['record_end'])
                    {
                        throw new RuntimeException('Modified upgrade requires manual refund review.');
                    }
                    $model->downgradeUserUpgrade($record, false);
                }
            }
            // Confirmed purchases cannot become pending/canceled; refunds are terminal.
            if ($row['status'] !== 'CHARGEBACKED'
                && !($row['status'] === 'CONFIRMED' && in_array($remote['status'], ['PENDING', 'CANCELED'], true)))
            {
                $update['status'] = $remote['status'];
            }
            $db->update(self::TABLE, $update, $db->quoteInto('request_key = ?', $key));
            XenForo_Db::commit($db);
            return $update['status'] ?? $row['status'];
        }
        catch (Throwable $e)
        {
            XenForo_Db::rollbackAll($db);
            throw $e;
        }
    }
}
