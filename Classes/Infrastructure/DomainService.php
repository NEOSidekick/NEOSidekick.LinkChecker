<?php

namespace NEOSidekick\LinkChecker\Infrastructure;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;

class DomainService
{
    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @return Domain[]
     */
    public function findAllSitesPrimaryDomain(): array
    {
        /** @var Site[] $sites */
        $sites = $this->siteRepository->findAll()->toArray();
        $domainsWithUniqueSite = [];
        foreach ($sites as $site) {
            $primaryDomain = $site->getPrimaryDomain();
            if ($primaryDomain) {
                $domainsWithUniqueSite[] = $primaryDomain;
            }
        }
        return $domainsWithUniqueSite;
    }
}
