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
        // https://neos-project.slack.com/archives/C04V4C6B0/p1668168503014459
        $qB = $this->entityManager->createQueryBuilder()
            ->delete(ResultItem::class);

        $query = $qB->getQuery();
        $query->execute();
    }

    public function removeAllNonIgnored(): void
    {
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
        $existingResultItem = $this->findOneByData([
            'domain' => $resultItem->getDomain(),
            'source' => $resultItem->getSource(),
            'target' => $resultItem->getTarget(),
            'statusCode' => $resultItem->getStatusCode(),
        ]);

        if ($existingResultItem instanceof ResultItem) {
            return;
        }

        parent::add($resultItem);
    }

    private function findOneByData(array $properties = [], $cacheResult = false): ?ResultItem
    {
        $query = $this->createQuery();
        $constraints = [];
        foreach ($properties as $propertyName => $propertyValue) {
            $constraints[] = $query->equals($propertyName, $propertyValue);
        }
        return $query
            ->matching($query->logicalAnd($constraints))
            ->execute($cacheResult)
            ->getFirst();
    }
}
