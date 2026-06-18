<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Domain\Model;

use DateTimeImmutable;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use Neos\Flow\Tests\UnitTestCase;

class ResultItemTest extends UnitTestCase
{
    /** @test */
    public function fingerprintUsesNodeSourceIdentifierWhenAvailable(): void
    {
        $first = $this->createResultItem(
            'WWW.NEOS.EU',
            '40044084-39B1-4F4A-8117-E3CE4F593F81',
            '/sites/www/old-path',
            'node://6A819CAD-8DD0-475F-BA2F-6F30B16CB51E',
            404
        );
        $second = $this->createResultItem(
            'www.neos.eu',
            '40044084-39b1-4f4a-8117-e3ce4f593f81',
            '/sites/www/new-path',
            'node://6a819cad-8dd0-475f-ba2f-6f30b16cb51e',
            404
        );

        self::assertSame($first->getFingerprint(), $second->getFingerprint());
    }

    /** @test */
    public function fingerprintUsesNormalizedSourceUrlForExternalCrawlerRows(): void
    {
        $first = $this->createResultItem(
            'example.com',
            null,
            'HTTPS://Example.com:443/source-path#headline',
            'HTTP://Target.example:80/Missing?b=1#ignored',
            404
        );
        $second = $this->createResultItem(
            'EXAMPLE.COM',
            null,
            'https://example.com/source-path',
            'http://target.example/Missing?b=1',
            404
        );

        self::assertSame($first->getFingerprint(), $second->getFingerprint());
    }

    /** @test */
    public function fingerprintChangesWhenStatusCodeChanges(): void
    {
        $first = $this->createResultItem('example.com', null, 'https://example.com/a', 'https://target.example/b', 404);
        $second = $this->createResultItem('example.com', null, 'https://example.com/a', 'https://target.example/b', 410);

        self::assertNotSame($first->getFingerprint(), $second->getFingerprint());
    }

    /** @test */
    public function fingerprintChangesWhenTargetChanges(): void
    {
        $first = $this->createResultItem('example.com', null, 'https://example.com/a', 'https://target.example/b', 404);
        $second = $this->createResultItem('example.com', null, 'https://example.com/a', 'https://target.example/c', 404);

        self::assertNotSame($first->getFingerprint(), $second->getFingerprint());
    }

    /** @test */
    public function mergePreservesEarliestCreatedAtLatestCheckedAtAndRicherFields(): void
    {
        $existing = $this->createResultItem(
            'example.com',
            'source-id',
            null,
            'node://target-id',
            404,
            '2026-06-18 12:00:00',
            '2026-06-18 12:00:00'
        );
        $incoming = $this->createResultItem(
            'example.com',
            'source-id',
            '/sites/example/source',
            'node://target-id',
            404,
            '2026-06-18 11:00:00',
            '2026-06-18 13:00:00'
        );
        $incoming->setTargetPath('/sites/example/target');
        $incoming->setIgnore(true);

        $existing->mergeFrom($incoming);

        self::assertSame('2026-06-18 11:00:00', $existing->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-18 13:00:00', $existing->getCheckedAt()->format('Y-m-d H:i:s'));
        self::assertSame('/sites/example/source', $existing->getSourcePath());
        self::assertSame('/sites/example/target', $existing->getTargetPath());
        self::assertTrue($existing->getIgnore());
    }

    private function createResultItem(
        string $domain,
        ?string $source,
        ?string $sourcePath,
        string $target,
        int $statusCode,
        string $createdAt = '2026-06-18 12:00:00',
        string $checkedAt = '2026-06-18 12:00:00'
    ): ResultItem {
        $resultItem = new ResultItem();
        $resultItem->setDomain($domain);
        $resultItem->setSource($source);
        $resultItem->setSourcePath($sourcePath);
        $resultItem->setTarget($target);
        $resultItem->setStatusCode($statusCode);
        $resultItem->setCreatedAt(new DateTimeImmutable($createdAt));
        $resultItem->setCheckedAt(new DateTimeImmutable($checkedAt));
        $resultItem->refreshFingerprint();

        return $resultItem;
    }
}
