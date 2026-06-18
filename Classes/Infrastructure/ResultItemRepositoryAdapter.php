<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Persistence\Repository;

/**
 * @Flow\Scope("singleton")
 */
class ResultItemRepositoryAdapter extends Repository implements ResultItemRepositoryInterface
{
    const ENTITY_CLASSNAME = ResultItem::class;

    /**
     * @var array<string, ResultItem>
     */
    private array $resultItemsByFingerprint = [];

    /**
     * @var EntityManagerInterface
     * @Flow\Inject
     */
    protected $entityManager;

    public function findAll(): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching($query->equals('ignore', 0));
        $query->setOrderings(
            [
                'source' => QueryInterface::ORDER_ASCENDING,
            ]
        );
        return $query->execute();
    }

    public function remove($resultItem): void
    {
        parent::remove($resultItem);
    }

    public function truncate(): void
    {
        $this->resultItemsByFingerprint = [];

        // https://neos-project.slack.com/archives/C04V4C6B0/p1668168503014459
        $qB = $this->entityManager->createQueryBuilder()
            ->delete(ResultItem::class);

        $query = $qB->getQuery();
        $query->execute();
    }

    public function removeAllNonIgnored(): void
    {
        $this->resultItemsByFingerprint = [];

        $query = $this->createQuery();
        $query->matching($query->equals('ignore', false));
        $resultItems = $query->execute();
        foreach ($resultItems as $resultItem) {
            $this->remove($resultItem);
        }
    }

    /**
     * @throws IllegalObjectTypeException
     */
    public function ignore(ResultItem $resultItem): void
    {
        $resultItem->setIgnore(true);
        $this->update($resultItem);
    }

    /**
     * @throws IllegalObjectTypeException
     */
    public function add($resultItem): void
    {
        $resultItem->refreshFingerprint();
        $fingerprint = $resultItem->getFingerprint();

        $existingResultItem = $this->resultItemsByFingerprint[$fingerprint] ?? $this->findOneByFingerprint($fingerprint);

        if ($existingResultItem instanceof ResultItem) {
            $existingResultItem->mergeFrom($resultItem);
            // Re-index by the current fingerprint, as mergeFrom() may enrich
            // identity-relevant fields and recompute it.
            $this->resultItemsByFingerprint[$existingResultItem->getFingerprint()] = $existingResultItem;
            $this->update($existingResultItem);
            $this->persistenceManager->persistAll();
            return;
        }

        $this->resultItemsByFingerprint[$fingerprint] = $resultItem;
        parent::add($resultItem);
        // Persist immediately so the unique fingerprint is enforced incrementally:
        // findOneByFingerprint() only sees flushed rows, so without this a later
        // result with the same fingerprint (e.g. two URLs that normalize equally)
        // would schedule a second insert and violate the unique index on flush.
        // add() is only called for broken links, so the number of flushes stays small.
        $this->persistenceManager->persistAll();
    }

    private function findOneByFingerprint(string $fingerprint, bool $cacheResult = false): ?ResultItem
    {
        $query = $this->createQuery();

        return $query
            ->matching($query->equals('fingerprint', $fingerprint))
            ->execute($cacheResult)
            ->getFirst();
    }
}
