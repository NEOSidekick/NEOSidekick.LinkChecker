<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Controller\Backend;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Booting\Scripts;
use Neos\Flow\I18n\Translator;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Service\LinkingService;

/**
 * @Flow\Scope("singleton")
 */
class ModuleController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @var Translator
     * @Flow\Inject
     */
    protected $translator;

    /**
     * @var ResultItemRepositoryInterface
     * @Flow\Inject
     */
    protected $resultItemRepository;

    /**
     * @var ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var ConfigurationManager
     * @Flow\Inject
     */
    protected $configurationManager;

    public function indexAction(): void
    {
        $resultItems = $this->resultItemRepository->findAll();
        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $resultItems = array_map(function (ResultItem $resultItem) {
            $target = $resultItem->getTarget();
            if (str_starts_with($target, 'node://')) {
                $this->injectPageTitleInResultItem($resultItem, $target);
            }
            return $resultItem;
        }, $resultItems->toArray());

        $this->view->assignMultiple([
            'links' => $resultItems,
            'flashMessages' => $flashMessages,
        ]);
    }

    public function runAction(): void
    {
        $this->resultItemRepository->removeAllNonIgnored();
        $settings = $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.Flow');
        Scripts::executeCommandAsync("neosidekick.linkchecker:checklinks:crawl", $settings);
    }

    public function deleteAction(ResultItem $resultItem): void
    {
        $this->resultItemRepository->remove($resultItem);

        $this->addFlashMessage(sprintf('%s deleted', $resultItem->getSource()));
        $this->redirect('index');
    }

    public function ignoreAction(ResultItem $resultItem): void
    {
        $this->resultItemRepository->ignore($resultItem);

        $this->addFlashMessage(sprintf('%s ignored', $resultItem->getSource()));
        $this->redirect('index');
    }

    private function injectPageTitleInResultItem(ResultItem $resultItem, string $target): void
    {
        preg_match(LinkingService::PATTERN_SUPPORTED_URIS, $target, $matches);
        $nodeIdentifier = $matches[2];

        $baseContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);
        $targetNode = $baseContext->getNodeByIdentifier($nodeIdentifier);

        if (!($targetNode instanceof NodeInterface)) {
            return;
        }

        $resultItem->setTargetPageTitle($targetNode->getProperty('title'));
    }
}
