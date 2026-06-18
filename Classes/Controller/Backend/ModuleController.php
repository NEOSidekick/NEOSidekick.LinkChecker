<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Controller\Backend;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Presentation\ResultItemViewFactory;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Booting\Scripts;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;

/**
 * @Flow\Scope("singleton")
 */
class ModuleController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @var ResultItemRepositoryInterface
     * @Flow\Inject
     */
    protected $resultItemRepository;

    /**
     * @var ConfigurationManager
     * @Flow\Inject
     */
    protected $configurationManager;

    /**
     * @var ResultItemViewFactory
     * @Flow\Inject
     */
    protected $resultItemViewFactory;

    public function indexAction(): void
    {
        $resultItems = $this->resultItemRepository->findAll();
        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();

        $resultItems = array_map(
            fn (ResultItem $resultItem) => $this->resultItemViewFactory->create($resultItem, $this->controllerContext),
            $resultItems->toArray()
        );

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
}
