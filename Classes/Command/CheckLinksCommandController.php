<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Command;

use Doctrine\ORM\EntityManagerInterface;
use NEOSidekick\LinkChecker\Domain\Crawler\ContentNodeCrawler;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Infrastructure\DomainService;
use NEOSidekick\LinkChecker\Infrastructure\LogAndPersistResultCrawlObserver;
use NEOSidekick\LinkChecker\Infrastructure\UriFactory;
use NEOSidekick\LinkChecker\Infrastructure\CrawlNonExcludedUrls;
use NEOSidekick\LinkChecker\Domain\Notification\NotificationServiceInterface;
use NEOSidekick\LinkChecker\Infrastructure\OriginUrlException;
use NEOSidekick\LinkChecker\Infrastructure\WebCrawlerFactory;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Utility\ObjectAccess;
use Psr\Http\Message\UriInterface;

/**
 * @Flow\Scope("singleton")
 */
class CheckLinksCommandController extends CommandController
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
     * @Flow\InjectConfiguration(path="notifications")
     * @var array
     */
    protected $notificationSettings;

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
     * @var EntityManagerInterface
     * @Flow\Inject
     */
    protected $entityManager;

    /**
     * Audit stored link checker results.
     */
    public function auditCommand(): void
    {
        $connection = $this->entityManager->getConnection();
        $tableName = 'neosidekick_linkchecker_domain_model_resultitem';

        $totalRows = (int)$connection->fetchOne("SELECT COUNT(*) FROM {$tableName}");
        $activeRows = (int)$connection->fetchOne("SELECT COUNT(*) FROM {$tableName} WHERE `ignore` = 0");
        $ignoredRows = (int)$connection->fetchOne("SELECT COUNT(*) FROM {$tableName} WHERE `ignore` = 1");
        $distinctFingerprints = (int)$connection->fetchOne(
            "SELECT COUNT(DISTINCT fingerprint) FROM {$tableName} WHERE fingerprint IS NOT NULL AND fingerprint <> ''"
        );
        $duplicateFingerprintGroups = (int)$connection->fetchOne(
            "SELECT COUNT(*) FROM (
                SELECT fingerprint
                FROM {$tableName}
                WHERE fingerprint IS NOT NULL AND fingerprint <> ''
                GROUP BY fingerprint
                HAVING COUNT(*) > 1
            ) duplicate_fingerprints"
        );
        $activeTargetLevelIssues = (int)$connection->fetchOne(
            "SELECT COUNT(DISTINCT CONCAT_WS('|', COALESCE(domain, ''), COALESCE(target, ''), COALESCE(statuscode, '')))
            FROM {$tableName}
            WHERE `ignore` = 0"
        );
        $activeServerErrorRows = (int)$connection->fetchOne(
            "SELECT COUNT(*) FROM {$tableName} WHERE `ignore` = 0 AND statuscode >= 500"
        );

        $this->outputLine('Link checker audit');
        $this->outputLine('------------------');
        $this->outputLine(sprintf('Rows total: %d', $totalRows));
        $this->outputLine(sprintf('Rows active: %d', $activeRows));
        $this->outputLine(sprintf('Rows ignored: %d', $ignoredRows));
        $this->outputLine(sprintf('Distinct fingerprints: %d', $distinctFingerprints));
        $this->outputLine(sprintf('Duplicate fingerprint groups: %d', $duplicateFingerprintGroups));
        $this->outputLine(sprintf('Target-level active issues: %d', $activeTargetLevelIssues));
        $this->outputLine(sprintf('Source occurrences: %d', $activeRows));
        $this->outputLine(sprintf('Active 5xx rows after revalidation: %d', $activeServerErrorRows));
    }

    /**
     * Clear all stored errors
     *
     * @param bool $keepIgnored ignored errors will not be deleted
     */
    public function clearCommand(bool $keepIgnored = false): void
    {
        if ($keepIgnored) {
            $this->resultItemRepository->removeAllNonIgnored();
        } else {
            $this->resultItemRepository->truncate();
        }
    }

    /**
     * Crawl for invalid node links and external links
     *
     * @param bool $withNotification sends email notification after scan
     */
    public function crawlCommand(bool $withNotification = false): void
    {
        $this->legacyHackPrettyUrls();
        $domainsToCrawl = $this->domainService->findAllSitesPrimaryDomain();
        $this->ensureDomainsNotEmpty($domainsToCrawl);
        $this->crawlNodesCommandImplementation($domainsToCrawl);
        $this->crawlExternalCommandImplementation($domainsToCrawl);
        if ($withNotification) {
            $this->sendNotificationIfNecessary($this->countBrokenNonIgnored(), $this->createLinkCheckerDashboardUriFromStuff($domainsToCrawl));
        }
    }

    /**
     * Crawl for invalid links within nodes
     *
     * This command crawls an url for invalid internal and external links
     *
     * @param bool $withNotification sends email notification after scan
     */
    public function crawlNodesCommand(bool $withNotification = false): void
    {
        $this->legacyHackPrettyUrls();
        $domainsToCrawl = $this->domainService->findAllSitesPrimaryDomain();
        $this->ensureDomainsNotEmpty($domainsToCrawl);
        $this->crawlNodesCommandImplementation($domainsToCrawl);
        if ($withNotification) {
            $this->sendNotificationIfNecessary($this->countBrokenNonIgnored(), $this->createLinkCheckerDashboardUriFromStuff($domainsToCrawl));
        }
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
        $this->legacyHackPrettyUrls();
        $domainsToCrawl = $this->domainService->findAllSitesPrimaryDomain();
        $this->ensureDomainsNotEmpty($domainsToCrawl);
        $this->crawlExternalCommandImplementation($domainsToCrawl);
        if ($withNotification) {
            $this->sendNotificationIfNecessary($this->countBrokenNonIgnored(), $this->createLinkCheckerDashboardUriFromStuff($domainsToCrawl));
        }
    }

    private function crawlNodesCommandImplementation(array $domainsToCrawl): void
    {
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

            $messages = $this->contentNodeCrawler->crawl($subgraph, $domainToCrawl);

            foreach ($messages as $message) {
                $this->output->outputFormatted('<error>' . $message . '</error>');
            }
            $this->output->outputLine(sprintf("Problems for domain %s: %s", $domainToCrawl->__toString(), \count($messages)));

            $this->persistenceManager->persistAll();
            $subgraph->getFirstLevelNodeCache()->flush();
            unset($subgraph, $messages);
            gc_collect_cycles();
        }

        if ($restoreBaseUriProviderSingleton) {
            $restoreBaseUriProviderSingleton();
        }
    }

    private function crawlExternalCommandImplementation(array $domainsToCrawl): void
    {
        $crawlProfile = new CrawlNonExcludedUrls();

        foreach ($domainsToCrawl as $domainToCrawl) {
            $crawlObserver = new LogAndPersistResultCrawlObserver();
            $crawler = $this->webCrawlerFactory->createCrawler($crawlProfile, $crawlObserver);
            $url = $this->uriFactory->createFromDomain($domainToCrawl);

            try {
                $this->outputLine("Start scanning $url");
                $this->outputLine('');

                try {
                    $crawler->startCrawling($url);
                } catch (OriginUrlException $originUrlException) {
                    $this->outputFormatted("<error>{$originUrlException->getMessage()}</error>");
                    $this->outputFormatted("<error>The configured site domain $url could not be reached, please check if the URL is correct.</error>");
                    return;
                }
            } catch (\InvalidArgumentException $exception) {
                $this->outputLine('ERROR:  ' . $exception->getMessage());
            } finally {
                $this->persistenceManager->persistAll();
                unset($crawler, $crawlObserver);
                gc_collect_cycles();
            }
        }
    }

    /** @throws StopCommandException */
    private function ensureDomainsNotEmpty(array $domains): void
    {
        if (count($domains) == 0) {
            $message = $this->translator->translatebyid('noDomainsFound', [], null, null, 'Modules', 'NEOSidekick.LinkChecker');
            $this->output->outputFormatted('<error>' . $message . '</error>');
            $this->quit();
        }
    }

    private function createLinkCheckerDashboardUriFromStuff(array $domains): UriInterface
    {
        $firstDomain = $domains[0];
        $baseUri = $this->uriFactory->createFromDomain($firstDomain);
        return $this->createBackendModuleUri("management/link-checker", "index", $baseUri);
    }

    private function createBackendModuleUri(string $module, string $moduleAction, UriInterface $baseUri): UriInterface
    {
        $request = new ServerRequest("GET", $baseUri);
        $actionRequest = Mvc\ActionRequest::fromHttpRequest($request);
        $uriBuilder = new Mvc\Routing\UriBuilder();
        $uriBuilder->setRequest($actionRequest);

        $uriBuilder->setCreateAbsoluteUri(true);

        return new Uri($uriBuilder->uriFor(
            'index',
            [
                'module' => $module,
                'moduleArguments' => ['@action' => $moduleAction]
            ],
            'Backend\Module',
            'Neos.Neos'
        ));
    }

    /**
     * Count broken (non-ignored) findings. Warnings such as auth walls or rate limits are excluded
     * so that notifications only fire for links that genuinely need fixing.
     */
    private function countBrokenNonIgnored(): int
    {
        $count = 0;
        foreach ($this->resultItemRepository->findAll() as $resultItem) {
            if ($resultItem->isBroken()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Send notification about the result of the link check run. The notification service can be configured.
     * Default is the emailService.
     */
    private function sendNotificationIfNecessary(int $errorCount, UriInterface $linkCheckerDashboardUri): void
    {
        if ($errorCount <= 0) {
            return;
        }

        if (!$this->notificationSettings['enabled']) {
            return;
        }

        $notificationServiceClass = trim($this->notificationSettings['service']);
        if ($notificationServiceClass === '') {
            $errorMessage = 'No notification service has been configured, but the notification handling is enabled';
            throw new \InvalidArgumentException($errorMessage, 1540201992);
        }

        $notificationService = $this->objectManager->get($notificationServiceClass);

        if (!$notificationService instanceof NotificationServiceInterface) {
            throw new \InvalidArgumentException(
                "NotificationService $notificationServiceClass, doesnt implement the NotificationServiceInterface",
                1668164428
            );
        }
        $notificationService->sendNotification(
            $this->notificationSettings['subject'] ?? '',
            [
                'errorCount' => $errorCount,
                'linkCheckerDashboardUri' => $linkCheckerDashboardUri
            ]
        );
    }

    /**
     * @return callable restore the original state
     */
    private function hackTheConfiguredBaseUriOfTheBaseUriProviderSingleton(UriInterface $baseUri): callable
    {
        assert($this->baseUriProvider instanceof BaseUriProvider);

        static $originalConfiguredBaseUri;
        if (!isset($originalConfiguredBaseUri)) {
            $originalConfiguredBaseUri = ObjectAccess::getProperty($this->baseUriProvider, "configuredBaseUri", true);
        }

        ObjectAccess::setProperty($this->baseUriProvider, "configuredBaseUri", (string)$baseUri, true);

        return function () use ($originalConfiguredBaseUri) {
            ObjectAccess::setProperty($this->baseUriProvider, "configuredBaseUri", $originalConfiguredBaseUri, true);
        };
    }

    private function legacyHackPrettyUrls(): void
    {
        // with Flow 7.1 not needed anymore
        // see FEATURE: Enable URL Rewriting by default
        // https://github.com/neos/flow-development-collection/pull/2459
        // needed for \NEOSidekick\LinkChecker\Domain\Factory\UriBuilderFactory::create
        if (!isset($_SERVER['FLOW_REWRITEURLS']) || $_SERVER['FLOW_REWRITEURLS'] !== '1') {
            $_SERVER['FLOW_REWRITEURLS'] = '1';
        }
    }
}
