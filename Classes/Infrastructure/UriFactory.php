<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Domain;
use Psr\Http\Message\UriInterface;

/**
 * @Flow\Scope("singleton")
 */
class UriFactory
{
    public function createFromDomain(Domain $domain): UriInterface
    {
        $uri = new Uri();
        $scheme = $domain->getScheme() ?: 'http';
        $uri = $uri->withScheme($scheme);
        $uri = $uri->withHost($domain->getHostname());
        if ($domain->getPort() !== null) {
            $uri = match ($scheme) {
                'http' => $uri->withPort($domain->getPort() !== 80 ? $domain->getPort() : null),
                'https' => $uri->withPort($domain->getPort() !== 443 ? $domain->getPort() : null),
                default => $uri->withPort($domain->getPort() !== null ? $domain->getPort() : null),
            };
        }
        return $uri;
    }
}
