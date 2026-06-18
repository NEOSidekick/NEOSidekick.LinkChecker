<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequestFactory;
use Neos\Flow\Mvc\Exception\InvalidActionNameException;
use Neos\Flow\Mvc\Exception\InvalidArgumentNameException;
use Neos\Flow\Mvc\Exception\InvalidArgumentTypeException;
use Neos\Flow\Mvc\Exception\InvalidControllerNameException;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Model\Domain;
use Psr\Http\Message\ServerRequestFactoryInterface;

/**
 * @Flow\Scope("singleton")
 */
class UriBuilderFactory
{
    /**
     * @var ServerRequestFactoryInterface
     * @Flow\Inject
     */
    protected $serverRequestFactory;

    /**
     * @var ActionRequestFactory
     * @Flow\Inject
     */
    protected $actionRequestFactory;

    /**
     * @var UriFactory
     * @Flow\Inject
     */
    protected $uriFactory;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @throws InvalidActionNameException
     * @throws InvalidArgumentNameException
     * @throws InvalidArgumentTypeException
     * @throws InvalidControllerNameException
     */
    public function createFromDomain(Domain $domain): UriBuilder
    {
        if ($this->uriBuilder instanceof UriBuilder) {
            return $this->uriBuilder;
        }

        $routeParameters = RouteParameters::createEmpty()
            ->withParameter('requestUriHost', $domain->getHostname());

        $domainUri = $this->uriFactory->createFromDomain($domain);

        $fakeHttpRequest = $this->serverRequestFactory
            ->createServerRequest('GET', (string)$domainUri)
            ->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $routeParameters);

        $fakeActionRequest = $this->actionRequestFactory->createActionRequest($fakeHttpRequest);

        $this->uriBuilder = new UriBuilder();
        $this->uriBuilder->setRequest($fakeActionRequest);

        return $this->uriBuilder;
    }
}
