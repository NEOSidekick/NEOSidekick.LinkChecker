<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class LinkHealthScoreService
{
    /**
     * @param array<ResultItemView> $links
     */
    public function create(array $links, int $totalInternalPageCount): array
    {
        $affectedSourcePageCount = $this->countAffectedSourcePages($links);
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
            // Warnings (auth walls, rate limits, redirects) must not lower the health score.
            if (!$link->isBroken()) {
                continue;
            }
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
