<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use NEOSidekick\LinkChecker\Domain\Crawler\ContentNodeCrawler;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Utility\ObjectAccess;
use Psr\Http\Message\UriInterface;

/**
 * @Flow\Scope("singleton")
 */
class LinkCheckRunner
{
    /**
     * @var Translator
     * @Flow\Inject
     */
    protected $translator;

    /**
     * @var DomainService
     * @Flow\Inject
     */
    protected $domainService;

    /**
     * @var ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var ContentNodeCrawler
     * @Flow\Inject
     */
    protected $contentNodeCrawler;

    /**
     * @var UriFactory
     * @Flow\Inject
     */
    protected $uriFactory;

    /**
     * @var WebCrawlerFactory
     * @Flow\Inject
     */
    protected $webCrawlerFactory;

    /**
     * @Flow\Inject
     * @var ResultItemRepositoryInterface
     */
    protected $resultItemRepository;

    /**
     * @Flow\Inject
     * @var IncrementalCrawlTracker
     */
    protected $incrementalCrawlTracker;

    /**
     * @var LinkCheckNotificationService
     * @Flow\Inject
     */
    protected $notificationService;

    /**
     * @var BaseUriProvider
     * @Flow\Inject(lazy=false)
     */
    protected $baseUriProvider;

    /**
     * @var PersistenceManagerInterface
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * @var ConsoleOutput
     * @Flow\Inject
     */
    protected $output;

    /**
     * @Flow\InjectConfiguration(path="incremental.enabled")
     * @var bool
     */
    protected $incrementalEnabled = false;

    public function clearResults(bool $keepIgnored = false): void
    {
        if ($keepIgnored) {
            $this->resultItemRepository->removeAllNonIgnored();
            return;
        }

        $this->resultItemRepository->truncate();
    }

    /**
     * @throws NoDomainsFoundException
     */
    public function crawl(bool $withNotification = false, bool $onlyChanged = false): void
    {
        $this->legacyHackPrettyUrls();
        $domainsToCrawl = $this->findDomainsToCrawl();
        $this->crawlNodesForDomains($domainsToCrawl, $onlyChanged);
        $this->crawlExternalLinksForDomains($domainsToCrawl);
        $this->sendNotificationIfRequested($withNotification, $domainsToCrawl);
    }

    /**
     * @throws NoDomainsFoundException
     */
    public function crawlNodes(bool $withNotification = false, bool $onlyChanged = false): void
    {
        $this->legacyHackPrettyUrls();
        $domainsToCrawl = $this->findDomainsToCrawl();
        $this->crawlNodesForDomains($domainsToCrawl, $onlyChanged);
        $this->sendNotificationIfRequested($withNotification, $domainsToCrawl);
    }

    /**
     * @throws NoDomainsFoundException
     */
    public function crawlExternalLinks(bool $withNotification = false): void
    {
        $this->legacyHackPrettyUrls();
        $domainsToCrawl = $this->findDomainsToCrawl();
        $this->crawlExternalLinksForDomains($domainsToCrawl);
        $this->sendNotificationIfRequested($withNotification, $domainsToCrawl);
    }

    /**
     * @return Domain[]
     * @throws NoDomainsFoundException
     */
    private function findDomainsToCrawl(): array
    {
        $domainsToCrawl = $this->domainService->findAllSitesPrimaryDomain();
        if (count($domainsToCrawl) > 0) {
            return $domainsToCrawl;
        }

        $message = $this->translator->translateById('noDomainsFound', [], null, null, 'Modules', 'NEOSidekick.LinkChecker');
        throw new NoDomainsFoundException($message, 1668863281);
    }

    /**
     * @param Domain[] $domainsToCrawl
     */
    private function crawlNodesForDomains(array $domainsToCrawl, bool $onlyChanged = false): void
    {
        $incremental = $onlyChanged || $this->incrementalEnabled;
        $changedSince = $incremental ? $this->incrementalCrawlTracker->getLastRun() : null;
        // Anchor the next incremental window to the start of this run so concurrent edits are not missed.
        $crawlStartedAt = new \DateTimeImmutable();

        if ($incremental) {
            $this->output->outputLine($changedSince === null
                ? 'Incremental mode: no previous run found, performing a full crawl.'
                : sprintf('Incremental mode: only checking nodes changed since %s.', $changedSince->format('Y-m-d H:i:s')));
        }

        /** @var callable|null $restoreBaseUriProviderSingleton */
        $restoreBaseUriProviderSingleton = null;
        foreach ($domainsToCrawl as $domainToCrawl) {
            $baseUriOfDomain = $this->uriFactory->createFromDomain($domainToCrawl);
            $restoreBaseUriProviderSingleton = $this->hackTheConfiguredBaseUriOfTheBaseUriProviderSingleton($baseUriOfDomain);

            /** @var ContentContext $subgraph */
            $subgraph = $this->contextFactory->create([
                'currentSite' => $domainToCrawl->getSite(),
                'currentDomain' => $domainToCrawl,
            ]);

            $messages = $this->contentNodeCrawler->crawl($subgraph, $domainToCrawl, $changedSince);

            foreach ($messages as $message) {
                $this->output->outputFormatted('<error>' . $message . '</error>');
            }
            $this->output->outputLine(sprintf('Problems for domain %s: %s', $domainToCrawl->__toString(), \count($messages)));

            $this->persistenceManager->persistAll();
            $subgraph->getFirstLevelNodeCache()->flush();
            unset($subgraph, $messages);
            gc_collect_cycles();
        }

        if ($restoreBaseUriProviderSingleton) {
            $restoreBaseUriProviderSingleton();
        }

        if ($incremental) {
            $this->incrementalCrawlTracker->setLastRun($crawlStartedAt);
        }
    }

    /**
     * @param Domain[] $domainsToCrawl
     */
    private function crawlExternalLinksForDomains(array $domainsToCrawl): void
    {
        $crawlProfile = new CrawlNonExcludedUrls();

        foreach ($domainsToCrawl as $domainToCrawl) {
            $crawlObserver = new LogAndPersistResultCrawlObserver();
            $crawlObserver->setCrawledDomain($domainToCrawl);
            $url = $this->uriFactory->createFromDomain($domainToCrawl);
            $crawler = $this->webCrawlerFactory->createCrawler($crawlProfile, $crawlObserver, $url->getHost());

            try {
                $this->output->outputLine("Start scanning $url");
                $this->output->outputLine('');

                try {
                    $crawler->startCrawling($url);
                } catch (OriginUrlException $originUrlException) {
                    $this->output->outputFormatted("<error>{$originUrlException->getMessage()}</error>");
                    $this->output->outputFormatted("<error>The configured site domain $url could not be reached, please check if the URL is correct.</error>");
                    return;
                }
            } catch (\InvalidArgumentException $exception) {
                $this->output->outputLine('ERROR:  ' . $exception->getMessage());
            } finally {
                $this->persistenceManager->persistAll();
                unset($crawler, $crawlObserver);
                gc_collect_cycles();
            }
        }
    }

    /**
     * @param Domain[] $domainsToCrawl
     */
    private function sendNotificationIfRequested(bool $withNotification, array $domainsToCrawl): void
    {
        if (!$withNotification) {
            return;
        }

        $this->notificationService->sendNotificationForDomainsIfNecessary($domainsToCrawl);
    }

    /**
     * @return callable restore the original state
     */
    private function hackTheConfiguredBaseUriOfTheBaseUriProviderSingleton(UriInterface $baseUri): callable
    {
        assert($this->baseUriProvider instanceof BaseUriProvider);

        static $originalConfiguredBaseUri;
        if (!isset($originalConfiguredBaseUri)) {
            $originalConfiguredBaseUri = ObjectAccess::getProperty($this->baseUriProvider, 'configuredBaseUri', true);
        }

        ObjectAccess::setProperty($this->baseUriProvider, 'configuredBaseUri', (string)$baseUri, true);

        return function () use ($originalConfiguredBaseUri) {
            ObjectAccess::setProperty($this->baseUriProvider, 'configuredBaseUri', $originalConfiguredBaseUri, true);
        };
    }

    private function legacyHackPrettyUrls(): void
    {
        // With Flow 7.1 not needed anymore; see FEATURE: Enable URL Rewriting by default.
        // Needed for \NEOSidekick\LinkChecker\Domain\Factory\UriBuilderFactory::create.
        if (!isset($_SERVER['FLOW_REWRITEURLS']) || $_SERVER['FLOW_REWRITEURLS'] !== '1') {
            $_SERVER['FLOW_REWRITEURLS'] = '1';
        }
    }
}
