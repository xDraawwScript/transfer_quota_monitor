<?php

declare(strict_types=1);

namespace OCA\TransferQuotaMonitor\Listener;

use OCA\TransferQuotaMonitor\Service\TransferQuotaService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;

class FileOperationListener implements IEventListener {
    /** @var TransferQuotaService */
    private $quotaService;
    
    /** @var LoggerInterface */
    private $logger;
    
    private static $processedNodes = [];
    
    public function __construct(TransferQuotaService $quotaService, LoggerInterface $logger) {
        $this->quotaService = $quotaService;
        $this->logger = $logger;
    }
    
    public function handle(Event $event): void {
        if ($event instanceof NodeWrittenEvent) {
            $node = $event->getNode();
            $owner = $node->getOwner();
            if ($owner) {
                $fileId = $node->getId();
                $userId = $owner->getUID();
                $size = $node->getSize();
                // if size is 0 it's likely a metadata update or similar we ignore it to avoid false positives
                if ($size === 0) {
                    return;
                }
                // avoid tracking the same file multiple times in a short period
                $key = 'upload-' . $fileId . '-' . $userId . '-' . $size;
                
                // if we've already processed this node recently skip it to avoid double counting
                if (isset(self::$processedNodes[$key])) {
                    return;
                }
                
                self::$processedNodes[$key] = true;
                $this->logger->info('Upload tracked via NodeWrittenEvent: ' . $size . ' bytes for user ' . $userId, [
                    'app' => 'transfer_quota_monitor'
                ]);
                $this->quotaService->addUserTransfer($userId, $size);
            }
        }
        
        if (count(self::$processedNodes) > 100) {
            self::$processedNodes = array_slice(self::$processedNodes, -50, null, true);
        }
    }
}