<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Infrastructure;

use NEOSidekick\LinkChecker\Infrastructure\DomainService;
use NEOSidekick\LinkChecker\Infrastructure\SitePageCountService;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\ContentContext;

class SitePageCountServiceTest extends UnitTestCase
{
    /** @test */
    public function countTotalVisibleDocumentPagesCreatesContextWithoutHiddenOrInaccessibleContent(): void
    {
        $site = $this->createMock(Site::class);

        $domain = $this->createMock(Domain::class);
        $domain->method('getSite')->willReturn($site);

        $domainService = $this->createMock(DomainService::class);
        $domainService->method('findAllSitesPrimaryDomain')->willReturn([$domain]);

        $context = $this->getMockBuilder(ContentContext::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentSiteNode'])
            ->getMock();
        $context->method('getCurrentSiteNode')->willReturn(null);

        $contextFactory = $this->createMock(ContextFactoryInterface::class);
        $contextFactory
            ->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (array $configuration) use ($site, $domain): bool {
                return $configuration['workspaceName'] === 'live'
                    && $configuration['currentSite'] === $site
                    && $configuration['currentDomain'] === $domain
                    && $configuration['invisibleContentShown'] === false
                    && $configuration['inaccessibleContentShown'] === false
                    && $configuration['removedContentShown'] === false;
            }))
            ->willReturn($context);

        $service = new SitePageCountService();
        $this->inject($service, 'domainService', $domainService);
        $this->inject($service, 'contextFactory', $contextFactory);

        self::assertSame(0, $service->countTotalVisibleDocumentPages());
    }
}
