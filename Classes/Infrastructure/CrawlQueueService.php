<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use Flowpack\JobQueue\Common\Job\JobManager;
use Flowpack\JobQueue\Common\Queue\QueueManager;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Job\CrawlLinksJob;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * @Flow\Scope("singleton")
 */
class CrawlQueueService
{
    /**
     * @var ResultItemRepositoryInterface
     * @Flow\Inject
     */
    protected $resultItemRepository;

    /**
     * @var JobManager
     * @Flow\Inject
     */
    protected $jobManager;

    /**
     * @var QueueManager
     * @Flow\Inject
     */
    protected $queueManager;

    /**
     * @var PersistenceManagerInterface
     * @Flow\Inject
     */
    protected $persistenceManager;

    public function resetResultsAndQueueCrawl(): array
    {
        $this->resultItemRepository->removeAllNonIgnored();
        $this->persistenceManager->persistAll();

        $status = $this->getStatus();
        if ($status['active'] === 0) {
            $this->jobManager->queue(CrawlLinksJob::QUEUE_NAME, new CrawlLinksJob(false, false, false));

            return $this->getStatus() + [
                'queued' => true,
                'reset' => true,
            ];
        }

        return $status + [
            'queued' => false,
            'reset' => true,
        ];
    }

    public function getStatus(): array
    {
        $queue = $this->queueManager->getQueue(CrawlLinksJob::QUEUE_NAME);
        $ready = $queue->countReady();
        $reserved = $queue->countReserved();
        $failed = $queue->countFailed();

        return [
            'ready' => $ready,
            'reserved' => $reserved,
            'failed' => $failed,
            'active' => $ready + $reserved,
            'total' => $ready + $reserved + $failed,
            'canQueue' => $ready + $reserved === 0,
        ];
    }
}
