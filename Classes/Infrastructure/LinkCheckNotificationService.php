<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Domain\Notification\NotificationServiceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Psr\Http\Message\UriInterface;

/**
 * @Flow\Scope("singleton")
 */
class LinkCheckNotificationService
{
    /**
     * @Flow\Inject
     * @var ResultItemRepositoryInterface
     */
    protected $resultItemRepository;

    /**
     * @var UriFactory
     * @Flow\Inject
     */
    protected $uriFactory;

    /**
     * @var ObjectManagerInterface
     * @Flow\Inject
     */
    protected $objectManager;

    /**
     * @Flow\InjectConfiguration(path="notifications")
     * @var array
     */
    protected $notificationSettings;

    /**
     * @param Domain[] $domains
     */
    public function sendNotificationForDomainsIfNecessary(array $domains): void
    {
        $errorCount = $this->countBrokenNonIgnored();
        if ($errorCount <= 0 || !$this->notificationSettings['enabled']) {
            return;
        }

        $notificationServiceClass = trim($this->notificationSettings['service']);
        if ($notificationServiceClass === '') {
            throw new \InvalidArgumentException(
                'No notification service has been configured, but the notification handling is enabled',
                1540201992
            );
        }

        $notificationService = $this->objectManager->get($notificationServiceClass);

        if (!$notificationService instanceof NotificationServiceInterface) {
            throw new \InvalidArgumentException(
                "NotificationService $notificationServiceClass, doesnt implement the NotificationServiceInterface",
                1668164428
            );
        }

        $notificationService->sendNotification(
            $this->notificationSettings['subject'] ?? '',
            [
                'errorCount' => $errorCount,
                'linkCheckerDashboardUri' => $this->createLinkCheckerDashboardUri($domains),
            ]
        );
    }

    /**
     * Count broken (non-ignored) findings. Warnings such as auth walls or rate limits are excluded
     * so that notifications only fire for links that genuinely need fixing.
     */
    public function countBrokenNonIgnored(): int
    {
        $count = 0;
        foreach ($this->resultItemRepository->findAll() as $resultItem) {
            if ($resultItem->isBroken()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param Domain[] $domains
     */
    private function createLinkCheckerDashboardUri(array $domains): UriInterface
    {
        $firstDomain = $domains[0];
        $baseUri = $this->uriFactory->createFromDomain($firstDomain);

        return $this->createBackendModuleUri('management/link-checker', 'index', $baseUri);
    }

    private function createBackendModuleUri(string $module, string $moduleAction, UriInterface $baseUri): UriInterface
    {
        $request = new ServerRequest('GET', $baseUri);
        $actionRequest = Mvc\ActionRequest::fromHttpRequest($request);
        $uriBuilder = new Mvc\Routing\UriBuilder();
        $uriBuilder->setRequest($actionRequest);
        $uriBuilder->setCreateAbsoluteUri(true);

        return new Uri($uriBuilder->uriFor(
            'index',
            [
                'module' => $module,
                'moduleArguments' => ['@action' => $moduleAction],
            ],
            'Backend\Module',
            'Neos.Neos'
        ));
    }
}
