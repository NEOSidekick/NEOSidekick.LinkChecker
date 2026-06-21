<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Job;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Booting\Scripts;

class CrawlLinksJob implements JobInterface
{
    public const QUEUE_NAME = 'NEOSidekick.LinkChecker.Crawl';

    private const COMMAND_IDENTIFIER = 'neosidekick.linkchecker:checklinks:crawl';

    private const CLEAR_COMMAND_IDENTIFIER = 'neosidekick.linkchecker:checklinks:clear';

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow")
     * @var array
     */
    protected $flowSettings = [];

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

    public function execute(QueueInterface $queue, Message $message): bool
    {
        if ($this->clearExistingResults) {
            Scripts::executeCommand(self::CLEAR_COMMAND_IDENTIFIER, $this->flowSettings, true, ['keep-ignored' => '1']);
        }
        Scripts::executeCommand(self::COMMAND_IDENTIFIER, $this->flowSettings, true, $this->createCommandArguments());
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

    private function createCommandArguments(): array
    {
        $arguments = [];

        if ($this->withNotification) {
            $arguments['with-notification'] = '1';
        }
        if ($this->onlyChanged) {
            $arguments['only-changed'] = '1';
        }

        return $arguments;
    }
}
