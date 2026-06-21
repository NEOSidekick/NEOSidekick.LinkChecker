<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Command;

use NEOSidekick\LinkChecker\Infrastructure\LinkCheckAuditService;
use NEOSidekick\LinkChecker\Infrastructure\LinkCheckRunner;
use NEOSidekick\LinkChecker\Infrastructure\NoDomainsFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

/**
 * @Flow\Scope("singleton")
 */
class CheckLinksCommandController extends CommandController
{
    /**
     * @var LinkCheckAuditService
     * @Flow\Inject
     */
    protected $auditService;

    /**
     * @var LinkCheckRunner
     * @Flow\Inject
     */
    protected $linkCheckRunner;

    /**
     * Audit stored link checker results.
     */
    public function auditCommand(): void
    {
        $audit = $this->auditService->createAudit();

        $this->outputLine('Link checker audit');
        $this->outputLine('------------------');
        $this->outputLine(sprintf('Rows total: %d', $audit['totalRows']));
        $this->outputLine(sprintf('Rows active: %d', $audit['activeRows']));
        $this->outputLine(sprintf('Rows ignored: %d', $audit['ignoredRows']));
        $this->outputLine(sprintf('Distinct fingerprints: %d', $audit['distinctFingerprints']));
        $this->outputLine(sprintf('Duplicate fingerprint groups: %d', $audit['duplicateFingerprintGroups']));
        $this->outputLine(sprintf('Target-level active issues: %d', $audit['activeTargetLevelIssues']));
        $this->outputLine(sprintf('Source occurrences: %d', $audit['activeRows']));
        $this->outputLine(sprintf('Active 5xx rows after revalidation: %d', $audit['activeServerErrorRows']));
    }

    /**
     * Clear all stored errors
     *
     * @param bool $keepIgnored ignored errors will not be deleted
     */
    public function clearCommand(bool $keepIgnored = false): void
    {
        $this->linkCheckRunner->clearResults($keepIgnored);
    }

    /**
     * Crawl for invalid node links and external links
     *
     * @param bool $withNotification sends email notification after scan
     * @param bool $onlyChanged only check content nodes modified since the last incremental run
     */
    public function crawlCommand(bool $withNotification = false, bool $onlyChanged = false): void
    {
        $this->runCrawl(fn () => $this->linkCheckRunner->crawl($withNotification, $onlyChanged));
    }

    /**
     * Crawl for invalid links within nodes
     *
     * This command crawls an url for invalid internal and external links
     *
     * @param bool $withNotification sends email notification after scan
     * @param bool $onlyChanged only check content nodes modified since the last incremental run
     */
    public function crawlNodesCommand(bool $withNotification = false, bool $onlyChanged = false): void
    {
        $this->runCrawl(fn () => $this->linkCheckRunner->crawlNodes($withNotification, $onlyChanged));
    }

    /**
     * Crawl for invalid external links
     *
     * This command crawls the whole website for invalid external links
     *
     * @param bool $withNotification sends email notification after scan
     */
    public function crawlExternalLinksCommand(bool $withNotification = false): void
    {
        $this->runCrawl(fn () => $this->linkCheckRunner->crawlExternalLinks($withNotification));
    }

    private function runCrawl(callable $crawl): void
    {
        try {
            $crawl();
        } catch (NoDomainsFoundException $exception) {
            $this->output->outputFormatted('<error>' . $exception->getMessage() . '</error>');
            $this->quit();
        }
    }
}
