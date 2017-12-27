<?php

class ModelExtensionPaymentYandexMoney extends Model
{
    private $kassaModel;
    private $walletModel;
    private $billingModel;

    private $backupDirectory = 'yandex_money/backup';
    private $versionDirectory = 'yandex_money/updates';
    private $downloadDirectory = 'yandex_money';
    private $repository = 'yandex-money/yandex-money-ycms-v2-opencart';

    public function install()
    {
        $this->log('info', 'install yandex_money module');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `'.DB_PREFIX.'ya_money_payment` (
                `order_id`          INTEGER  NOT NULL,
                `payment_id`        CHAR(36) NOT NULL,
                `status`            ENUM(\'pending\', \'waiting_for_capture\', \'succeeded\', \'canceled\') NOT NULL,
                `amount`            DECIMAL(11, 2) NOT NULL,
                `currency`          CHAR(3) NOT NULL,
                `payment_method_id` CHAR(36) NOT NULL,
                `paid`              ENUM(\'Y\', \'N\') NOT NULL,
                `created_at`        DATETIME NOT NULL,
                `captured_at`       DATETIME NOT NULL DEFAULT \'0000-00-00 00:00:00\',
                `receipt`           TEXT DEFAULT NULL,

                CONSTRAINT `'.DB_PREFIX.'ya_money_payment_pk` PRIMARY KEY (`order_id`),
                CONSTRAINT `'.DB_PREFIX.'ya_money_payment_unq_payment_id` UNIQUE (`payment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=UTF8 COLLATE=utf8_general_ci;
        ');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `'.DB_PREFIX.'ya_money_refunds` (
                `refund_id`         CHAR(36) NOT NULL,
                `order_id`          INTEGER  NOT NULL,
                `payment_id`        CHAR(36) NOT NULL,
                `status`            ENUM(\'pending\', \'succeeded\', \'canceled\') NOT NULL,
                `amount`            DECIMAL(11, 2) NOT NULL,
                `currency`          CHAR(3) NOT NULL,
                `created_at`        DATETIME NOT NULL,
                `authorized_at`     DATETIME NOT NULL DEFAULT \'0000-00-00 00:00:00\',

                CONSTRAINT `'.DB_PREFIX.'ya_money_refunds_pk` PRIMARY KEY (`refund_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=UTF8 COLLATE=utf8_general_ci;
        ');
        $this->db->query('CREATE INDEX `'.DB_PREFIX.'ya_money_refunds_idx_order_id` ON `'.DB_PREFIX.'ya_money_refunds`(`order_id`)');
        $this->db->query('CREATE INDEX `'.DB_PREFIX.'ya_money_refunds_idx_payment_id` ON `'.DB_PREFIX.'ya_money_refunds`(`payment_id`)');
    }

    public function uninstall()
    {
        $this->log('info', 'uninstall yandex_money module');
        $this->db->query("DROP TABLE IF EXISTS `' . DB_PREFIX . 'ya_money_payment`;");
        $this->db->query("DROP TABLE IF EXISTS `' . DB_PREFIX . 'ya_money_refunds`;");
    }

    public function log($level, $message, $context = null)
    {
        if ($this->getKassaModel()->getDebugLog()) {
            $log     = new Log('yandex-money.log');
            $search  = array();
            $replace = array();
            if (!empty($context)) {
                foreach ($context as $key => $value) {
                    $search[]  = '{'.$key.'}';
                    $replace[] = $value;
                }
            }
            $sessionId = $this->session->getId();
            $userId    = 0;
            if (isset($this->session->data['user_id'])) {
                $userId = $this->session->data['user_id'];
            }
            if (empty($search)) {
                $log->write('['.$level.'] ['.$userId.'] ['.$sessionId.'] - '.$message);
            } else {
                $log->write(
                    '['.$level.'] ['.$userId.'] ['.$sessionId.'] - '
                    .str_replace($search, $replace, $message)
                );
            }
        }
    }

    /**
     * @param int $orderId
     *
     * @return string|null
     */
    public function findPaymentIdByOrderId($orderId)
    {
        $sql       = 'SELECT `payment_id` FROM `'.DB_PREFIX.'ya_money_payment` WHERE `order_id` = '.(int)$orderId;
        $resultSet = $this->db->query($sql);
        if (empty($resultSet) || empty($resultSet->num_rows)) {
            return null;
        }

        return $resultSet->row['payment_id'];
    }

    /**
     * @param int $orderId
     *
     * @return array
     */
    public function getOrderRefunds($orderId)
    {
        $sql       = 'SELECT * FROM `'.DB_PREFIX.'ya_money_refunds` WHERE `order_id` = '.(int)$orderId;
        $recordSet = $this->db->query($sql);
        $result    = array();
        foreach ($recordSet->rows as $record) {
            if ($record['status'] === \YaMoney\Model\RefundStatus::PENDING) {
                $record['status'] = $this->checkRefundStatus($record['refund_id']);
            }
            $result[] = $record;
        }

        return $result;
    }

    private function checkRefundStatus($refundId)
    {
        // @todo вернуть выгрузку рефанда после того, как в API появится метод получения информации о нём
        return;
        try {
            $refund = $this->getClient()->getRefundInfo($refundId);
        } catch (\Exception $e) {
            return;
        }
        $sql = 'UPDATE `'.DB_PREFIX.'ya_money_payment` SET `status` = \''
               .$this->db->escape($refund->getStatus()).'\'';
        if ($refund->getAuthorizedAt() !== null) {
            $sql .= ', `authorized_at` = \''.$refund->getAuthorizedAt()->format('Y-m-d H:i:s').'\'';
        }
        $sql .= ' WHERE `refund_id` = \''.$this->db->escape($refund->getId()).'\'';
        $this->db->escape($sql);
    }

    /**
     * @param string $paymentId
     *
     * @return \YaMoney\Model\PaymentInterface|null
     */
    public function fetchPaymentInfo($paymentId)
    {
        try {
            $payment = $this->getClient()->getPaymentInfo($paymentId);
        } catch (Exception $e) {
            $this->log('error', 'Failed to fetch payment info: '.$e->getMessage());
            $payment = null;
        }

        return $payment;
    }

    public function getKassaModel()
    {
        if ($this->kassaModel === null) {
            require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'yandex_money'.DIRECTORY_SEPARATOR.'YandexMoneyKassaModel.php';
            $this->kassaModel = new YandexMoneyKassaModel($this->config);
        }

        return $this->kassaModel;
    }

    public function getWalletModel()
    {
        if ($this->walletModel === null) {
            require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'yandex_money'.DIRECTORY_SEPARATOR.'YandexMoneyWalletModel.php';
            $this->walletModel = new YandexMoneyWalletModel($this->config);
        }

        return $this->walletModel;
    }

    public function getBillingModel()
    {
        if ($this->billingModel === null) {
            require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'yandex_money'.DIRECTORY_SEPARATOR.'YandexMoneyBillingModel.php';
            $this->billingModel = new YandexMoneyBillingModel($this->config);
        }

        return $this->billingModel;
    }

    public function carrierList()
    {
        $prefix = version_compare(VERSION, '2.3.0') >= 0 ? 'extension/' : '';
        $types  = array(
            'POST'     => "Доставка почтой",
            'PICKUP'   => "Самовывоз",
            'DELIVERY' => "Доставка курьером",
        );
        $this->load->model('extension/extension');
        $extensions = $this->model_extension_extension->getInstalled('shipping');
        foreach ($extensions as $key => $value) {
            if (!file_exists(DIR_APPLICATION.'controller/shipping/'.$value.'.php')) {
                unset($extensions[$key]);
            }
        }
        $data['extensions'] = array();
        $files              = glob(DIR_APPLICATION.'controller/shipping/*.php');
        if ($files) {
            foreach ($files as $file) {
                $extension = basename($file, '.php');
                if (in_array($extension, $extensions)) {
                    $this->load->language($prefix.'shipping/'.$extension);
                    $data['extensions'][] = array(
                        'name'       => $this->language->get('heading_title'),
                        'sort_order' => $this->config->get($extension.'_sort_order'),
                        'installed'  => in_array($extension, $extensions),
                        'ext'        => $extension,
                    );
                }
            }
        }
        $html      = '';
        $save_data = $this->config->get('yandex_money_pokupki_carrier');
        foreach ($data['extensions'] as $row) {
            $html .= '<div class="form-group">
                <label class="col-sm-4 control-label" for="yandex_money_pokupki_carrier">'.$row['name'].'</label>
                <div class="col-sm-8">
                    <select name="yandex_money_pokupki_carrier['.$row['ext'].']" id="yandex_money_pokupki_carrier" class="form-control">';
            foreach ($types as $t => $t_name) {
                $html .= '<option value="'.$t.'" '.((isset($save_data[$row['ext']]) && $save_data[$row['ext']] == $t) ? 'selected="selected"' : '').'>'.$t_name.'</option>';
            }
            $html .= '</select>
                </div>
            </div>';
        }

        return $html;
    }

    public function refundPayment($payment, $order, $amount, $comment)
    {
        try {
            $builder = \YaMoney\Request\Refunds\CreateRefundRequest::builder();
            $builder->setAmount($amount)
                    ->setCurrency(\YaMoney\Model\CurrencyCode::RUB)
                    ->setPaymentId($payment->getId())
                    ->setComment($comment);
            $request = $builder->build();
        } catch (Exception $e) {
            $this->log('error', 'Failed to create refund: '.$e->getMessage());

            return null;
        }

        try {
            $key   = 'refund-'.$order['order_id'].'-'.microtime(true);
            $tries = 0;
            do {
                $response = $this->getClient()->createRefund($request, $key);
                if ($response === null) {
                    $tries++;
                    if ($tries >= 3) {
                        break;
                    }
                    sleep(2);
                }
            } while ($response === null);
        } catch (Exception $e) {
            $this->log('error', 'Failed to create refund: '.$e->getMessage());

            return null;
        }

        if ($response !== null) {
            $this->insertRefund($response, $order['order_id']);
        }

        return $response;
    }

    /**
     * @param \YaMoney\Model\RefundInterface $refund
     * @param int $orderId
     */
    private function insertRefund($refund, $orderId)
    {
        $sql = 'INSERT INTO `'.DB_PREFIX.'ya_money_refunds`('
               .'`refund_id`, `order_id`, `payment_id`, `status`, `amount`, `currency`, `created_at`'
               .($refund->getAuthorizedAt() !== null ? ',`authorized_at`' : '')
               .') VALUES ('
               ."'".$this->db->escape($refund->getId())."',"
               .(int)$orderId.","
               ."'".$this->db->escape($refund->getPaymentId())."',"
               ."'".$this->db->escape($refund->getStatus())."',"
               ."'".$this->db->escape($refund->getAmount()->getValue())."',"
               ."'".$this->db->escape($refund->getAmount()->getCurrency())."',"
               ."'".$this->db->escape($refund->getCreatedAt()->format('Y-m-d H:i:s'))."'"
               .($refund->getAuthorizedAt() !== null ? (", '".$this->db->escape($refund->getCreatedAt()->format('Y-m-d H:i:s'))."'") : '')
               .')';
        $this->db->query($sql);
    }

    private $client;

    protected function getClient()
    {
        if ($this->client === null) {
            $this->client = new \YaMoney\Client\YandexMoneyApi();
            $this->client->setAuth(
                $this->getKassaModel()->getShopId(),
                $this->getKassaModel()->getPassword()
            );
            $this->client->setLogger($this);
        }

        return $this->client;
    }

    public function getBackupList()
    {
        $result = array();

        $this->preventDirectories();
        $dir = DIR_DOWNLOAD.'/'.$this->backupDirectory;

        $handle = opendir($dir);
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            if ($ext === 'zip') {
                $backup            = array(
                    'name' => pathinfo($entry, PATHINFO_FILENAME).'.zip',
                    'size' => $this->formatSize(filesize($dir.'/'.$entry)),
                );
                $parts             = explode('-', $backup['name'], 3);
                $backup['version'] = $parts[0];
                $backup['time']    = $parts[1];
                $backup['date']    = date('d.m.Y H:i:s', $parts[1]);
                $backup['hash']    = $parts[2];
                $result[]          = $backup;
            }
        }

        return $result;
    }

    public function createBackup($version)
    {
        $this->loadClasses();
        $this->preventDirectories();

        $sourceDirectory = dirname(realpath(DIR_CATALOG));
        $reader          = new \YandexMoney\Updater\ProjectStructure\ProjectStructureReader();
        $root            = $reader->readFile(dirname(__FILE__).'/yandex_money/'.$this->getMapFileName(),
            $sourceDirectory);

        $rootDir  = $version.'-'.time();
        $fileName = $rootDir.'-'.uniqid('', true).'.zip';

        $dir = DIR_DOWNLOAD.'/'.$this->backupDirectory;
        if (!file_exists($dir)) {
            if (!mkdir($dir)) {
                $this->log('error', 'Failed to create backup directory: '.$dir);

                return false;
            }
        }

        try {
            $fileName = $dir.'/'.$fileName;
            $archive  = new \YandexMoney\Updater\Archive\BackupZip($fileName, $rootDir);
            $archive->backup($root);
        } catch (Exception $e) {
            $this->log('error', 'Failed to create backup: '.$e->getMessage());

            return false;
        }

        return true;
    }

    public function restoreBackup($fileName)
    {
        $this->loadClasses();
        $this->preventDirectories();

        $fileName = DIR_DOWNLOAD.'/'.$this->backupDirectory.'/'.$fileName;
        if (!file_exists($fileName)) {
            $this->log('error', 'File "'.$fileName.'" not exists');

            return false;
        }

        try {
            $sourceDirectory = dirname(realpath(DIR_CATALOG));
            $archive         = new \YandexMoney\Updater\Archive\RestoreZip($fileName);
            $archive->restore('file_map.map', $sourceDirectory);
        } catch (Exception $e) {
            $this->log('error', $e->getMessage());
            if ($e->getPrevious() !== null) {
                $this->log('error', $e->getPrevious()->getMessage());
            }

            return false;
        }

        return true;
    }

    public function removeBackup($fileName)
    {
        $this->preventDirectories();

        $fileName = DIR_DOWNLOAD.'/'.$this->backupDirectory.'/'.str_replace(array('/', '\\'), array('', ''), $fileName);
        if (!file_exists($fileName)) {
            $this->log('error', 'File "'.$fileName.'" not exists');

            return false;
        }

        if (!unlink($fileName) || file_exists($fileName)) {
            $this->log('error', 'Failed to unlink file "'.$fileName.'"');

            return false;
        }

        return true;
    }

    public function checkModuleVersion($useCache = true)
    {
        $this->loadClasses();
        $this->preventDirectories();

        $file = DIR_DOWNLOAD.'/'.$this->downloadDirectory.'/version_log.txt';

        if ($useCache) {
            if (file_exists($file)) {
                $content = preg_replace('/\s+/', '', file_get_contents($file));
                if (!empty($content)) {
                    $parts = explode(':', $content);
                    if (count($parts) === 2) {
                        if (time() - $parts[1] < 3600 * 8) {
                            return array(
                                'tag'     => $parts[0],
                                'version' => preg_replace('/[^\d\.]+/', '', $parts[0]),
                                'time'    => $parts[1],
                                'date'    => $this->dateDiffToString($parts[1]),
                            );
                        }
                    }
                }
            }
        }

        $connector = new GitHubConnector();
        $version   = $connector->getLatestRelease($this->repository);
        if (empty($version)) {
            return array();
        }

        $cache = $version.':'.time();
        file_put_contents($file, $cache);

        return array(
            'tag'     => $version,
            'version' => preg_replace('/[^\d\.]+/', '', $version),
            'time'    => time(),
            'date'    => $this->dateDiffToString(time()),
        );
    }

    public function downloadLastVersion($tag, $useCache = true)
    {
        $this->loadClasses();
        $this->preventDirectories();

        $dir = DIR_DOWNLOAD.'/'.$this->versionDirectory;
        if (!file_exists($dir)) {
            if (!mkdir($dir)) {
                $this->log('error', 'Не удалось создать директорию '.$dir);

                return false;
            }
        } elseif ($useCache) {
            $fileName = $dir.'/'.$tag.'.zip';
            if (file_exists($fileName)) {
                return $fileName;
            }
        }

        $connector = new GitHubConnector();
        $fileName  = $connector->downloadRelease($this->repository, $tag, $dir);
        if (empty($fileName)) {
            $this->log('error', 'Не удалось загрузить архив с обновлением');

            return false;
        }

        return $fileName;
    }

    public function unpackLastVersion($fileName)
    {
        if (!file_exists($fileName)) {
            $this->log('error', 'File "'.$fileName.'" not exists');

            return false;
        }

        try {
            $sourceDirectory = dirname(realpath(DIR_CATALOG));
            $archive         = new \YandexMoney\Updater\Archive\RestoreZip($fileName);
            $archive->restore($this->getMapFileName(), $sourceDirectory);
        } catch (Exception $e) {
            $this->log('error', $e->getMessage());
            if ($e->getPrevious() !== null) {
                $this->log('error', $e->getPrevious()->getMessage());
            }

            return false;
        }

        return true;
    }

    public function getChangeLog($currentVersion, $newVersion)
    {
        $connector = new GitHubConnector();

        $dir          = DIR_DOWNLOAD.'/'.$this->downloadDirectory;
        $newChangeLog = $dir.'/CHANGELOG-'.$newVersion.'.md';
        if (!file_exists($newChangeLog)) {
            $fileName = $connector->downloadLatestChangeLog($this->repository, $dir);
            if (!empty($fileName)) {
                rename($dir.'/'.$fileName, $newChangeLog);
            }
        }

        $oldChangeLog = $dir.'/CHANGELOG-'.$currentVersion.'.md';
        if (!file_exists($oldChangeLog)) {
            $fileName = $connector->downloadLatestChangeLog($this->repository, $dir);
            if (!empty($fileName)) {
                rename($dir.'/'.$fileName, $oldChangeLog);
            }
        }

        $result = '';
        if (file_exists($newChangeLog)) {
            $result = $connector->diffChangeLog($oldChangeLog, $newChangeLog);
        }

        return $result;
    }

    private function formatSize($size)
    {
        static $sizes = array(
            'B',
            'kB',
            'MB',
            'GB',
            'TB',
        );

        $i = 0;
        while ($size > 1024) {
            $size /= 1024.0;
            $i++;
        }

        return number_format($size, 2, '.', ',').'&nbsp;'.$sizes[$i];
    }

    private function loadClasses()
    {
        if (!class_exists('GitHubConnector')) {
            $path = dirname(__FILE__).DIRECTORY_SEPARATOR.'yandex_money'.DIRECTORY_SEPARATOR.'Updater'.DIRECTORY_SEPARATOR;
            require_once $path.'GitHubConnector.php';
            require_once $path.'ProjectStructure/EntryInterface.php';
            require_once $path.'ProjectStructure/DirectoryEntryInterface.php';
            require_once $path.'ProjectStructure/FileEntryInterface.php';
            require_once $path.'ProjectStructure/AbstractEntry.php';
            require_once $path.'ProjectStructure/DirectoryEntry.php';
            require_once $path.'ProjectStructure/FileEntry.php';
            require_once $path.'ProjectStructure/ProjectStructureReader.php';
            require_once $path.'ProjectStructure/ProjectStructureWriter.php';
            require_once $path.'ProjectStructure/RootDirectory.php';
            require_once $path.'Archive/BackupZip.php';
            require_once $path.'Archive/RestoreZip.php';
        }
    }

    private function dateDiffToString($timestamp)
    {
        return date('d.m.Y H:i:s', $timestamp);
    }

    private function preventDirectories()
    {
        $this->checkDirectory(DIR_DOWNLOAD.'/'.$this->downloadDirectory);
        $this->checkDirectory(DIR_DOWNLOAD.'/'.$this->backupDirectory);
        $this->checkDirectory(DIR_DOWNLOAD.'/'.$this->versionDirectory);
    }

    private function checkDirectory($directoryName)
    {
        if (!file_exists($directoryName)) {
            mkdir($directoryName);
        }
        if (!is_dir($directoryName)) {
            throw new RuntimeException('Invalid configuration: "'.$directoryName.'" is not directory');
        }
    }

    private function getMapFileName()
    {
        if (version_compare(VERSION, '2.3.0') >= 0) {
            return 'opencart-2.3.0.map';
        }
        if (version_compare(VERSION, '2.2.0') >= 0
            && version_compare(VERSION, '2.3.0') < 0
        ) {
            return 'opencart-2.2.0.map';
        }
        if (version_compare(VERSION, '2.1.0') >= 0
            && version_compare(VERSION, '2.2.0') < 0
        ) {
            return 'opencart-2.1.0.map';
        }
    }
}

class ModelPaymentYandexMoney extends ModelExtensionPaymentYandexMoney
{
}