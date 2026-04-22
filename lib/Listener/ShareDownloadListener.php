<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Listener;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\AppFramework\Http\Response;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeFileDownloadedEvent;
use OCP\Share\Events\BeforeShareDownloadedEvent;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ShareDownloadListener implements IEventListener {
    /** @var IUserSession */
    private $userSession;
    
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var IRootFolder */
    private $rootFolder;
    
    /** @var LoggerInterface */
    private $logger;
    
    public function __construct(
        IUserSession $userSession,
        TransferQuotaService $quotaService,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->quotaService = $quotaService;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }
    // not used in our context but could be useful for future features
    public function handle(Event $event): void {
        $this->logger->debug('ShareDownloadListener received event: ' . get_class($event), [
            'app' => 'transfer_quota_monitor'
        ]);
        if ($event instanceof BeforeFileDownloadedEvent) {
            $file = $event->getFile();
            $owner = $file->getOwner();
            if (!$owner) {
                return;
            }
            $size = $file->getSize();
            $userId = $owner->getUID();
            if ($this->quotaService->isQuotaExceeded($userId, $size)) {
                $this->logger->warning('Direct Download blocked, limit exceeded for ' . $userId);
                throw new \OCP\Files\StorageNotAvailableException('Impossible');
            }

            try {
                $this->logger->info('Direct download tracked: ' . $size . ' bytes for user ' . $userId, [
                    'app' => 'transfer_quota_monitor',
                    'userId' => $userId,
                    'fileSize' => $size
                ]);
                $this->quotaService->addUserTransfer($userId, $size);
                
            } catch (\Exception $e) {
                $this->logger->error('Error tracking file download: ' . $e->getMessage(), [
                    'app' => 'transfer_quota_monitor',
                    'exception' => $e
                ]);
            }
        }
        if ($event instanceof BeforeShareDownloadedEvent) {
            $share = $event->getShare();
            $node = $share->getNode();
            
            if ($node->isDirectory()) {
                return; 
            }
            $owner = $node->getOwner();
            if (!$owner) {
                return;
            }
            $size = $node->getSize();
            $userId = $owner->getUID();
            if ($this->quotaService->isQuotaExceeded($userId, $size)) {
                $this->logger->warning('Public Share Download blocked, limit exceeded for ' . $userId);
                throw new \OCP\Files\StorageNotAvailableException('impossible');
            }
            try {
                $this->logger->info('Share download tracked: ' . $size . ' bytes for user ' . $userId, [
                    'app' => 'transfer_quota_monitor',
                    'userId' => $userId,
                    'fileSize' => $size
                ]);
                $this->quotaService->addUserTransfer($userId, $size);
                
            } catch (\Exception $e) {
                $this->logger->error('Error tracking share download: ' . $e->getMessage(), [
                    'app' => 'transfer_quota_monitor',
                    'exception' => $e
                ]);
            }
        }
    }
}