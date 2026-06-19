<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use NEOSidekick\LinkChecker\Infrastructure\DomainService;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class LinkHealthScoreService
{
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
     * @param array<ResultItemView> $links
     */
    public function create(array $links): array
    {
        $affectedSourcePageCount = $this->countAffectedSourcePages($links);
        $totalInternalPageCount = $this->countLiveInternalPages();
        $scoreDenominator = max($totalInternalPageCount, $affectedSourcePageCount);
        $score = $scoreDenominator > 0
            ? (int)max(0, min(100, round((($scoreDenominator - $affectedSourcePageCount) / $scoreDenominator) * 100)))
            : 100;

        return [
            'score' => $score,
            'affectedSourcePageCount' => $affectedSourcePageCount,
            'totalInternalPageCount' => $totalInternalPageCount,
            'scoreDenominator' => $scoreDenominator,
            'needleRotation' => -90 + (int)round($score * 1.8),
            'level' => $this->level($score),
        ];
    }

    /**
     * @param array<ResultItemView> $links
     */
    private function countAffectedSourcePages(array $links): int
    {
        $sourceKeys = [];
        foreach ($links as $link) {
            $sourceKeys[$this->sourceKey($link)] = true;
        }

        return \count($sourceKeys);
    }

    private function sourceKey(ResultItemView $link): string
    {
        return implode('|', [
            $link->getDomain(),
            $link->getResultItem()->getSource() ?? '',
            $link->getResultItem()->getSourcePath() ?? $link->getSourceFrontendUri(),
        ]);
    }

    private function countLiveInternalPages(): int
    {
        $count = 0;

        foreach ($this->domainService->findAllSitesPrimaryDomain() as $domain) {
            $context = $this->contextFactory->create([
                'workspaceName' => 'live',
                'currentSite' => $domain->getSite(),
                'currentDomain' => $domain,
                'invisibleContentShown' => false,
                'inaccessibleContentShown' => false,
                'removedContentShown' => false,
            ]);

            $siteNode = $context->getCurrentSiteNode();
            if ($siteNode instanceof NodeInterface || $siteNode instanceof TraversableNodeInterface) {
                $count += $this->countDocumentNodes($siteNode);
            }
        }

        return $count;
    }

    private function countDocumentNodes(NodeInterface|TraversableNodeInterface $node): int
    {
        $count = $node->getNodeType()->isOfType('Neos.Neos:Document') ? 1 : 0;

        foreach ($node->findChildNodes() as $childNode) {
            if ($childNode instanceof NodeInterface || $childNode instanceof TraversableNodeInterface) {
                $count += $this->countDocumentNodes($childNode);
            }
        }

        return $count;
    }

    private function level(int $score): string
    {
        if ($score >= 90) {
            return 'excellent';
        }

        if ($score >= 70) {
            return 'good';
        }

        if ($score >= 40) {
            return 'needsWork';
        }

        return 'critical';
    }
}
