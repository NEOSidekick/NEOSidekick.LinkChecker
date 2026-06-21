<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Job;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use NEOSidekick\LinkChecker\Infrastructure\LinkCheckRunner;
use Neos\Flow\Annotations as Flow;

class CrawlLinksJob implements JobInterface
{
    public const QUEUE_NAME = 'NEOSidekick.LinkChecker.Crawl';

    /**
     * @Flow\Inject
     * @var LinkCheckRunner
     */
    protected $linkCheckRunner;

    /**
     * @var bool
     */
    protected $withNotification;

    /**
     * @var bool
     */
    protected $onlyChanged;

    /**
     * @var bool
     */
    protected $clearExistingResults;

    public function __construct(bool $withNotification = false, bool $onlyChanged = false, bool $clearExistingResults = true)
    {
        $this->withNotification = $withNotification;
        $this->onlyChanged = $onlyChanged;
        $this->clearExistingResults = $clearExistingResults;
    }

    public function __sleep(): array
    {
        return ['withNotification', 'onlyChanged', 'clearExistingResults'];
    }

    public function execute(QueueInterface $queue, Message $message): bool
    {
        if ($this->clearExistingResults) {
            $this->linkCheckRunner->clearResults(true);
        }
        $this->linkCheckRunner->crawl($this->withNotification, $this->onlyChanged);
        return true;
    }

    public function getLabel(): string
    {
        $options = [];
        if ($this->withNotification) {
            $options[] = 'with notification';
        }
        if ($this->onlyChanged) {
            $options[] = 'only changed nodes';
        }
        if ($this->clearExistingResults) {
            $options[] = 'clear existing results';
        }

        return sprintf(
            'NEOSidekick.LinkChecker crawl%s',
            $options === [] ? '' : ' (' . implode(', ', $options) . ')'
        );
    }
}
