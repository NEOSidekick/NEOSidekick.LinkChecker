<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Controller\Backend;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Infrastructure\BackendStatisticsService;
use NEOSidekick\LinkChecker\Infrastructure\CrawlQueueService;
use NEOSidekick\LinkChecker\Infrastructure\SitePageCountService;
use NEOSidekick\LinkChecker\Presentation\GroupedResultItemListView;
use NEOSidekick\LinkChecker\Presentation\LinkHealthScoreService;
use NEOSidekick\LinkChecker\Presentation\ResultItemFilterOptionView;
use NEOSidekick\LinkChecker\Presentation\ResultItemGroupChildView;
use NEOSidekick\LinkChecker\Presentation\ResultItemGroupView;
use NEOSidekick\LinkChecker\Presentation\ResultItemGroupingService;
use NEOSidekick\LinkChecker\Presentation\ResultItemView;
use NEOSidekick\LinkChecker\Presentation\ResultItemViewFactory;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;

/**
 * @Flow\Scope("singleton")
 */
class ModuleController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @var ResultItemRepositoryInterface
     * @Flow\Inject
     */
    protected $resultItemRepository;

    /**
     * @var ResultItemViewFactory
     * @Flow\Inject
     */
    protected $resultItemViewFactory;

    /**
     * @var ResultItemGroupingService
     * @Flow\Inject
     */
    protected $resultItemGroupingService;

    /**
     * @var LinkHealthScoreService
     * @Flow\Inject
     */
    protected $linkHealthScoreService;

    /**
     * @var CrawlQueueService
     * @Flow\Inject
     */
    protected $crawlQueueService;

    /**
     * @var SitePageCountService
     * @Flow\Inject
     */
    protected $sitePageCountService;

    /**
     * @var BackendStatisticsService
     * @Flow\Inject
     */
    protected $backendStatisticsService;

    /**
     * @Flow\InjectConfiguration(path="backend.resultLimit")
     */
    protected int $resultLimit = 200;

    public function indexAction(
        string $groupBy = ResultItemGroupingService::MODE_TARGET,
        string $targetType = 'all',
        string $domain = 'all',
        string $statusCode = 'all',
        string $impact = ResultItemGroupingService::IMPACT_ALL,
        int $limit = 0,
        bool $highImpactOnly = false
    ): void
    {
        $limit = $this->normalizeResultLimit($limit);
        $statistics = $this->backendStatisticsService->create();
        $activeImpact = $highImpactOnly && $impact === ResultItemGroupingService::IMPACT_ALL
            ? ResultItemGroupingService::IMPACT_3_PLUS
            : $impact;
        $resultItems = $this->resultItemRepository->findFilteredNonIgnored($limit, $targetType, $domain, $statusCode, $activeImpact);
        $totalListResultCount = $this->resultItemRepository->countFilteredNonIgnored($targetType, $domain, $statusCode, $activeImpact);
        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $links = array_map(
            fn (ResultItem $resultItem) => $this->resultItemViewFactory->create($resultItem, $this->controllerContext),
            $resultItems->toArray()
        );

        $groupedLinks = $this->resultItemGroupingService->group(
            $links,
            $groupBy,
            $targetType,
            $domain,
            $statusCode,
            $activeImpact,
            $activeImpact === ResultItemGroupingService::IMPACT_ALL
        );

        $this->view->assignMultiple([
            'links' => $links,
            'groupedLinks' => $this->serializeGroupedLinks(
                $groupedLinks,
                $this->linkHealthScoreService->createFromAffectedSourcePageCount(
                    $statistics['affectedBrokenSourcePageCount'],
                    $this->sitePageCountService->countTotalVisibleDocumentPages()
                ),
                $limit,
                $statistics,
                $totalListResultCount
            ),
            'flashMessages' => $flashMessages,
            'queueStatus' => $this->crawlQueueService->getStatus(),
        ]);
    }

    private function normalizeResultLimit(int $limit): int
    {
        if ($limit <= 0) {
            return max(1, $this->resultLimit);
        }

        return $limit;
    }

    private function serializeGroupedLinks(
        GroupedResultItemListView $groupedLinks,
        array $healthScore,
        int $limit,
        array $statistics,
        int $totalListResultCount
    ): array
    {
        $groups = $groupedLinks->getGroups();
        $totalResultCount = $totalListResultCount;
        $loadedResultCount = min($groupedLinks->getRawCount(), $totalResultCount);
        $nextLimit = min($limit + max(1, $this->resultLimit), $totalResultCount);
        $hasMoreResults = $totalResultCount > $loadedResultCount && $nextLimit > $limit;

        return [
            'groups' => $this->serializeGroups($groups, $groupedLinks->getActiveMode()),
            'modeOptions' => array_map(
                fn (ResultItemFilterOptionView $option) => $this->serializeOption($option, [
                    'groupBy' => $option->getIdentifier(),
                    'targetType' => $groupedLinks->getActiveTargetType(),
                    'domain' => $groupedLinks->getActiveDomain(),
                    'statusCode' => $groupedLinks->getActiveStatusCode(),
                    'impact' => $groupedLinks->getActiveImpact(),
                ], false),
                $groupedLinks->getModeOptions()
            ),
            'targetTypeOptions' => array_map(
                fn (ResultItemFilterOptionView $option) => $this->serializeOption($option, [
                    'groupBy' => $groupedLinks->getActiveMode(),
                    'targetType' => $option->getIdentifier(),
                    'domain' => $groupedLinks->getActiveDomain(),
                    'statusCode' => $groupedLinks->getActiveStatusCode(),
                    'impact' => $groupedLinks->getActiveImpact(),
                ], true),
                $this->createTargetTypeOptionsFromStatistics($statistics)
            ),
            'domainOptions' => array_map(
                fn (ResultItemFilterOptionView $option) => $this->serializeOption($option, [
                    'groupBy' => $groupedLinks->getActiveMode(),
                    'targetType' => $groupedLinks->getActiveTargetType(),
                    'domain' => $option->getIdentifier(),
                    'statusCode' => $groupedLinks->getActiveStatusCode(),
                    'impact' => $groupedLinks->getActiveImpact(),
                ], true),
                $this->createDomainOptionsFromStatistics($statistics)
            ),
            'statusOptions' => array_map(
                fn (ResultItemFilterOptionView $option) => $this->serializeOption($option, [
                    'groupBy' => $groupedLinks->getActiveMode(),
                    'targetType' => $groupedLinks->getActiveTargetType(),
                    'domain' => $groupedLinks->getActiveDomain(),
                    'statusCode' => $option->getIdentifier(),
                    'impact' => $groupedLinks->getActiveImpact(),
                ], true),
                $this->createStatusOptionsFromStatistics($statistics)
            ),
            'impactOptions' => array_map(
                fn (ResultItemFilterOptionView $option) => $this->serializeOption($option, [
                    'groupBy' => $groupedLinks->getActiveMode(),
                    'targetType' => $groupedLinks->getActiveTargetType(),
                    'domain' => $groupedLinks->getActiveDomain(),
                    'statusCode' => $groupedLinks->getActiveStatusCode(),
                    'impact' => $option->getIdentifier(),
                ], true),
                $this->createImpactOptionsFromStatistics($statistics)
            ),
            'resetUri' => $this->createModuleIndexUri([]),
            'loadMoreUri' => $hasMoreResults ? $this->createModuleIndexUri([
                'groupBy' => $groupedLinks->getActiveMode(),
                'targetType' => $groupedLinks->getActiveTargetType(),
                'domain' => $groupedLinks->getActiveDomain(),
                'statusCode' => $groupedLinks->getActiveStatusCode(),
                'impact' => $groupedLinks->getActiveImpact(),
                'limit' => $nextLimit,
            ]) : null,
            'healthScore' => $healthScore,
            'summaryItems' => $this->createSummaryItemsFromStatistics($statistics),
            'activeMode' => $groupedLinks->getActiveMode(),
            'activeTargetType' => $groupedLinks->getActiveTargetType(),
            'activeDomain' => $groupedLinks->getActiveDomain(),
            'activeStatusCode' => $groupedLinks->getActiveStatusCode(),
            'activeImpact' => $groupedLinks->getActiveImpact(),
            'rawCount' => $groupedLinks->getRawCount(),
            'loadedResultCount' => $loadedResultCount,
            'totalResultCount' => $totalResultCount,
            'resultLimit' => $limit,
            'resultLimitIncrement' => max(1, $this->resultLimit),
            'hasMoreResults' => $hasMoreResults,
            'groupCount' => $groupedLinks->getGroupCount(),
            'hasResults' => $groupedLinks->hasResults(),
            'hasStoredResults' => $statistics['totalRows'] > 0,
            'hasActiveFilters' => $groupedLinks->hasActiveFilters(),
            'hasMultipleDomains' => \count($statistics['domainIssueCounts']) > 1,
        ];
    }

    /**
     * @param array<ResultItemGroupView> $groups
     */
    private function serializeGroups(array $groups, string $mode): array
    {
        $serializedGroups = [];

        foreach ($groups as $group) {
            $serializedGroups[] = $this->serializeGroup($group, $mode);
        }

        return $serializedGroups;
    }

    private function createSummaryItemsFromStatistics(array $statistics): array
    {
        $summaryItems = [
            [
                'label' => 'Groups',
                'translationId' => 'summary.groups',
                'value' => $statistics['totalIssues'],
                'icon' => 'fas fa-link',
            ],
            [
                'label' => 'Rows',
                'translationId' => 'summary.rows',
                'value' => $statistics['totalRows'],
                'icon' => 'fas fa-unlink',
            ],
        ];

        foreach ($this->createTargetTypeOptionsFromStatistics($statistics) as $option) {
            if ($option->getIdentifier() === 'all' || $option->getCount() === 0) {
                continue;
            }

            $summaryItems[] = [
                'label' => $option->getLabel(),
                'translationId' => $option->getTranslationId(),
                'value' => $option->getCount(),
                'icon' => $this->summaryIconForTargetType($option->getIdentifier()),
            ];
        }

        return $summaryItems;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    private function createTargetTypeOptionsFromStatistics(array $statistics): array
    {
        $counts = $statistics['targetTypeIssueCounts'];

        return [
            new ResultItemFilterOptionView('all', 'All target types', 'filter.allTargetTypes', $statistics['totalIssues']),
            new ResultItemFilterOptionView('internalNode', 'Internal node', 'targetType.internalNode', $counts['internalNode'] ?? 0),
            new ResultItemFilterOptionView('externalUrl', 'External URL', 'targetType.externalUrl', $counts['externalUrl'] ?? 0),
            new ResultItemFilterOptionView('otherProtocol', 'Other protocol', 'targetType.otherProtocol', $counts['otherProtocol'] ?? 0),
        ];
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    private function createDomainOptionsFromStatistics(array $statistics): array
    {
        $options = [new ResultItemFilterOptionView('all', 'All domains', 'filter.allDomains', $statistics['totalIssues'])];
        foreach ($statistics['domainIssueCounts'] as $domain => $count) {
            $options[] = new ResultItemFilterOptionView((string)$domain, (string)$domain, null, $count);
        }

        return $options;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    private function createStatusOptionsFromStatistics(array $statistics): array
    {
        $options = [new ResultItemFilterOptionView('all', 'All statuses', 'filter.allStatuses', $statistics['totalIssues'])];
        foreach ($statistics['statusIssueCounts'] as $statusCode => $count) {
            $options[] = new ResultItemFilterOptionView(
                (string)$statusCode,
                (string)$statusCode,
                $this->statusFilterTranslationId((string)$statusCode),
                $count
            );
        }

        return $options;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    private function createImpactOptionsFromStatistics(array $statistics): array
    {
        $counts = $statistics['impactIssueCounts'];

        return [
            new ResultItemFilterOptionView(ResultItemGroupingService::IMPACT_ALL, 'All impact', 'filter.impact.all', $statistics['totalIssues']),
            new ResultItemFilterOptionView(ResultItemGroupingService::IMPACT_10_PLUS, '10+ pages', 'filter.impact.10Plus', $counts[ResultItemGroupingService::IMPACT_10_PLUS] ?? 0),
            new ResultItemFilterOptionView(ResultItemGroupingService::IMPACT_3_PLUS, '3+ pages', 'filter.impact.3Plus', $counts[ResultItemGroupingService::IMPACT_3_PLUS] ?? 0),
            new ResultItemFilterOptionView(ResultItemGroupingService::IMPACT_LOW, '1-2 pages', 'filter.impact.low', $counts[ResultItemGroupingService::IMPACT_LOW] ?? 0),
        ];
    }

    private function statusFilterTranslationId(string $statusCode): string
    {
        return match ($statusCode) {
            '490' => 'filter.status.490',
            default => 'error.' . $statusCode,
        };
    }

    private function summaryIconForTargetType(string $targetType): string
    {
        return match ($targetType) {
            'internalNode' => 'fas fa-sitemap',
            'externalUrl' => 'fas fa-external-link-alt',
            'otherProtocol' => 'fas fa-plug',
            default => 'fas fa-link',
        };
    }

    private function serializeOption(ResultItemFilterOptionView $option, array $arguments, bool $showCount): array
    {
        return [
            'identifier' => $option->getIdentifier(),
            'label' => $option->getLabel(),
            'translationId' => $option->getTranslationId(),
            'count' => $option->getCount(),
            'showCount' => $showCount,
            'uri' => $this->createModuleIndexUri($arguments),
        ];
    }

    private function createModuleIndexUri(array $arguments): string
    {
        return '?' . http_build_query([
            'moduleArguments' => [
                '@action' => 'index',
                'groupBy' => $arguments['groupBy'] ?? ResultItemGroupingService::MODE_TARGET,
                'targetType' => $arguments['targetType'] ?? 'all',
                'domain' => $arguments['domain'] ?? 'all',
                'statusCode' => $arguments['statusCode'] ?? 'all',
                'impact' => $arguments['impact'] ?? ResultItemGroupingService::IMPACT_ALL,
                'limit' => $arguments['limit'] ?? max(1, $this->resultLimit),
            ],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function serializeGroup(ResultItemGroupView $group, string $mode): array
    {
        $children = $group->getChildren();
        $statusCode = $group->getStatusCode();
        $uri = $group->getUri();
        $uriIsValid = $uri !== null && $uri !== '' && $uri !== '#';

        $headerKind = null;
        $headerEditUri = null;
        $headerOpenUri = null;

        if ($mode === ResultItemGroupingService::MODE_TARGET) {
            if ($group->getTargetType() === 'internalNode' && $uriIsValid) {
                $headerKind = 'target';
                $headerEditUri = $uri;
            } elseif ($group->getTargetType() === 'externalUrl' && $uriIsValid) {
                $headerKind = 'target';
                $headerOpenUri = $uri;
            }
        } elseif ($mode === ResultItemGroupingService::MODE_SOURCE) {
            $headerKind = 'source';
            $firstChild = $children[0] ?? null;
            if ($firstChild instanceof ResultItemGroupChildView) {
                $sourceEditUri = $firstChild->getLink()->getSourceEditUri();
                if ($sourceEditUri !== null && $sourceEditUri !== '' && $sourceEditUri !== '#') {
                    $headerEditUri = $sourceEditUri;
                }
            }
            if ($uriIsValid) {
                $headerOpenUri = $uri;
            }
        }

        $showStatusInHeader = $statusCode !== null
            && \in_array($mode, [ResultItemGroupingService::MODE_TARGET, ResultItemGroupingService::MODE_STATUS], true);

        return [
            'key' => $group->getKey(),
            'label' => $group->getLabel(),
            'uri' => $uri,
            'secondaryLabel' => $group->getSecondaryLabel(),
            'statusCode' => $statusCode,
            'statusTranslationId' => $this->statusBadgeTranslationId($statusCode),
            'severity' => $this->groupSeverity($children, $statusCode),
            'targetType' => $group->getTargetType(),
            'mode' => $mode,
            'headerKind' => $headerKind,
            'headerEditUri' => $headerEditUri,
            'headerOpenUri' => $headerOpenUri,
            'showStatusInHeader' => $showStatusInHeader,
            'showSourceColumn' => $mode !== ResultItemGroupingService::MODE_SOURCE,
            'showTargetColumn' => $mode !== ResultItemGroupingService::MODE_TARGET,
            'showDomainPerRow' => $mode !== ResultItemGroupingService::MODE_DOMAIN,
            'children' => array_map(fn (ResultItemGroupChildView $child) => $this->serializeGroupChild($child), $children),
            'resultItemIds' => implode(',', array_filter(array_map(
                fn (ResultItemGroupChildView $child) => $this->persistenceManager->getIdentifierByObject($child->getLink()->getResultItem()),
                $children
            ))),
            'affectedSourceCount' => $group->getAffectedSourceCount(),
            'occurrenceCount' => $group->getOccurrenceCount(),
            'duplicateCount' => $group->getDuplicateCount(),
            'hasDuplicates' => $group->hasDuplicates(),
            'domainsLabel' => $group->getDomainsLabel(),
            'domainCount' => \count($group->domains),
            'lastCheckedAt' => $group->getLastCheckedAt(),
        ];
    }

    private function statusBadgeTranslationId(?int $statusCode): ?string
    {
        if ($statusCode === null) {
            return null;
        }

        return match ($statusCode) {
            490 => 'filter.status.490',
            default => 'error.' . $statusCode,
        };
    }

    private function statusSeverity(?int $statusCode): string
    {
        if ($statusCode === null) {
            return 'neutral';
        }

        if ($statusCode === 490 || ($statusCode >= 300 && $statusCode < 400)) {
            return 'warning';
        }

        if ($statusCode === 0 || $statusCode >= 400) {
            return 'error';
        }

        return 'neutral';
    }

    /**
     * Severity follows the persisted classification so that auth walls, bot blocks, rate limits and
     * Cloudflare challenges are presented as warnings rather than hard errors.
     */
    private function severityForState(string $state): string
    {
        return $state === ResultItem::STATE_WARNING ? 'warning' : 'error';
    }

    /**
     * @param array<ResultItemGroupChildView> $children
     */
    private function groupSeverity(array $children, ?int $statusCode): string
    {
        if ($statusCode === null) {
            return $this->statusSeverity($statusCode);
        }

        $firstChild = $children[0] ?? null;
        if ($firstChild instanceof ResultItemGroupChildView) {
            return $this->severityForState($firstChild->getLink()->getState());
        }

        return $this->statusSeverity($statusCode);
    }

    private function serializeGroupChild(ResultItemGroupChildView $child): array
    {
        return [
            'link' => $this->serializeLink($child->getLink()),
            'occurrenceCount' => $child->getOccurrenceCount(),
            'duplicateCount' => $child->getDuplicateCount(),
            'hasDuplicates' => $child->hasDuplicates(),
        ];
    }

    private function serializeLink(ResultItemView $link): array
    {
        $statusCode = $link->getStatusCode();

        return [
            'resultItem' => $link->getResultItem(),
            'resultItemId' => $this->persistenceManager->getIdentifierByObject($link->getResultItem()),
            'domain' => $link->getDomain(),
            'sourceLabel' => $link->getSourceLabel(),
            'sourcePageLabel' => $this->sourcePageLabel($link),
            'sourceFrontendUri' => $link->getSourceFrontendUri(),
            'sourceEditUri' => $link->getSourceEditUri(),
            'targetLabel' => $link->getTargetLabel(),
            'targetUri' => $link->getTargetUri(),
            'targetType' => $this->targetTypeForLink($link),
            'targetFallbackLabel' => $link->getTargetFallbackLabel(),
            'statusCode' => $statusCode,
            'statusTranslationId' => $this->statusBadgeTranslationId($statusCode),
            'severity' => $this->severityForState($link->getState()),
            'state' => $link->getState(),
            'checkedAt' => $link->getCheckedAt(),
        ];
    }

    private function sourcePageLabel(ResultItemView $link): string
    {
        $label = $link->getSourceLabel();
        $domain = $link->getDomain();

        if ($domain !== '' && str_starts_with($label, $domain)) {
            $rest = ltrim(substr($label, \strlen($domain)), " >");
            return $rest !== '' ? $rest : '/';
        }

        return $label;
    }

    private function targetTypeForLink(ResultItemView $link): string
    {
        $target = strtolower($link->getTargetFallbackLabel());

        if (str_starts_with($target, 'node://')) {
            return 'internalNode';
        }

        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            return 'externalUrl';
        }

        return 'otherProtocol';
    }

    public function runAction(): void
    {
        $this->view->assign('queueStatus', $this->crawlQueueService->resetResultsAndQueueCrawl());
    }

    public function deleteAction(ResultItem $resultItem): void
    {
        $this->resultItemRepository->remove($resultItem);

        $this->addFlashMessage(sprintf('%s deleted', $resultItem->getSource()));
        $this->redirect('index');
    }

    public function ignoreAction(ResultItem $resultItem): void
    {
        $this->resultItemRepository->ignore($resultItem);

        $this->addFlashMessage(sprintf('%s ignored', $resultItem->getSource()));
        $this->redirect('index');
    }

    /**
     * Mark every broken link in a group as fixed (removes the result items).
     *
     * @param string $resultItemIds Comma-separated persistence identifiers of the result items in the group
     */
    public function resolveGroupAction(string $resultItemIds = ''): void
    {
        $count = $this->applyToResultItems(
            $resultItemIds,
            fn (ResultItem $resultItem) => $this->resultItemRepository->remove($resultItem)
        );

        $this->addFlashMessage(sprintf('%d broken link(s) marked as fixed', $count));
        $this->redirect('index');
    }

    /**
     * Ignore every broken link in a group.
     *
     * @param string $resultItemIds Comma-separated persistence identifiers of the result items in the group
     */
    public function ignoreGroupAction(string $resultItemIds = ''): void
    {
        $count = $this->applyToResultItems(
            $resultItemIds,
            fn (ResultItem $resultItem) => $this->resultItemRepository->ignore($resultItem)
        );

        $this->addFlashMessage(sprintf('%d broken link(s) ignored', $count));
        $this->redirect('index');
    }

    private function applyToResultItems(string $identifiers, callable $apply): int
    {
        $count = 0;

        foreach (explode(',', $identifiers) as $identifier) {
            $identifier = trim($identifier);
            if ($identifier === '') {
                continue;
            }

            $resultItem = $this->persistenceManager->getObjectByIdentifier($identifier, ResultItem::class);
            if ($resultItem instanceof ResultItem) {
                $apply($resultItem);
                $count++;
            }
        }

        return $count;
    }
}
