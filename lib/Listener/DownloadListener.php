<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Listener;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Preview\BeforePreviewFetchedEvent;
use Psr\Log\LoggerInterface;


class DownloadListener implements IEventListener {
    protected $userSession;
    protected $connection;
    protected $quotaService;
    protected $logger;

    public function __construct(
        IUserSession $userSession,
        IDBConnection $connection,
        TransferQuotaService $quotaService,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->connection = $connection;
        $this->quotaService = $quotaService;
        $this->logger = $logger;
    }

    public function handle(Event $event): void {
        if ($event instanceof BeforeNodeReadEvent) {
            $node = $event->getNode();
            if ($node instanceof File) {
                $user = $this->userSession->getUser();
                $userId = $user ? $user->getUID() : null;
                if (!$userId) {
                    $owner = $node->getOwner();
                    if ($owner) {
                        $userId = $owner->getUID();
                    }
                }
                if ($userId) {
                    $fileSize = $node->getSize();
                    $request = \OC::$server->getRequest();
                    if ($request->getMethod() === 'PROPFIND') {
                        return;
                    }
                    // ignore video streaming requests
                    $range = $request->getHeader('Range');
                    if (!empty($range) && strpos($range, 'bytes=0-')===false) {
                        return;
                    }
                    
                    // anti spam filter to prevent multiple counts for the same download in a short time frame
                    $cache = \OC::$server->get(\OCP\ICacheFactory::class)->createDistributed('transfer_quota');
                    $cacheKey = 'dl_lock_' . $userId . '_' . $node->getId();
                    if ($cache->get($cacheKey) === true) {
                        return; 
                    }
                    if ($this->quotaService->isQuotaExceeded($userId, $fileSize)) {
                        $this->logger->warning('Download blocked, limit exceeded for ' . $userId);
                        throw new \OCP\Files\StorageNotAvailableException('transfer quota exceeded');
                    }
                    $cache->set($cacheKey, true, 30);
                    $this->processFileDownload($node, $userId);
                }
            }
        }
        if ($event instanceof BeforePreviewFetchedEvent && ($event->getHeight() > 256 || $event->getWidth() > 256)) {
            $this->logDownload(null);
        }
    }

    protected function processFileDownload(File $file, string $userId): void {
        try {
            $fileSize = $file->getSize();
            $filePath = $file->getPath();
            $this->logger->info('File download detected via WebDAV/Web: ' . $filePath . ' (' . $fileSize . ' bytes) by user ' . $userId, [
                'app' => 'transfer_quota_monitor'
            ]);
            $this->quotaService->addUserTransfer($userId, $fileSize);
            $this->logDownload($userId);
        } catch (\Exception $e) {
            $this->logger->error('Error processing file download: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
        }
    }
    
    protected function logDownload(?string $userId = null): void {
        if (!$userId) {
            $user = $this->userSession->getUser();
            if (!$user) return; 
            $userId = $user->getUID();
        }
        
        try {
            $selectQuery = $this->connection->getQueryBuilder();
            $selectQuery->select('configvalue')
                ->from('preferences')
                ->where($selectQuery->expr()->eq('userid', $selectQuery->createParameter('user')))
                ->andWhere($selectQuery->expr()->eq('configkey', $selectQuery->createParameter('action')))
                ->andWhere($selectQuery->expr()->eq('appid', $selectQuery->createParameter('appid')))
                ->setParameter('user', $userId)
                ->setParameter('action', 'read')
                ->setParameter('appid', 'transfer_quota_monitor');
                
            $result = $selectQuery->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            
            if ($row) {
                $newValue = (int)$row['configvalue'] + 1;
                
                $query = $this->connection->getQueryBuilder();
                $query->update('preferences')
                    ->set('configvalue', $query->createNamedParameter((string)$newValue))
                    ->where($query->expr()->eq('userid', $query->createParameter('user')))
                    ->andWhere($query->expr()->eq('configkey', $query->createParameter('action')))
                    ->andWhere($query->expr()->eq('appid', $query->createParameter('appid')))
                    ->setParameter('user', $userId)
                    ->setParameter('action', 'read')
                    ->setParameter('appid', 'transfer_quota_monitor');
                
                $query->executeStatement();
            } else {
                $query = $this->connection->getQueryBuilder();
                $query->insert('preferences')
                    ->values([
                        'userid' => $query->createParameter('user'),
                        'appid' => $query->createParameter('appid'),
                        'configkey' => $query->createParameter('action'),
                        'configvalue' => $query->createParameter('configvalue'),
                    ])
                    ->setParameter('user', $userId)
                    ->setParameter('appid', 'transfer_quota_monitor')
                    ->setParameter('action', 'read')
                    ->setParameter('configvalue', '1');
                
                $query->executeStatement();
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error logging download in preferences: ' . $e->getMessage(), [
                'app' => 'transfer_quota_monitor',
                'exception' => $e
            ]);
        }
    }
}