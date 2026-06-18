<?php

declare(strict_types=1);

namespace Neos\Flow\Core\Migrations;

/**
 * Adjusts code to package renaming from "CodeQ.LinkChecker" to "NEOSidekick.LinkChecker"
 */
class Version20260618120000 extends AbstractMigration
{
    public function getIdentifier(): string
    {
        return 'NEOSidekick.LinkChecker-20260618120000';
    }

    public function up(): void
    {
        $this->searchAndReplace('CodeQ\\LinkChecker', 'NEOSidekick\\LinkChecker', ['php', 'yaml']);
        $this->searchAndReplace('CodeQ.LinkChecker', 'NEOSidekick.LinkChecker', ['php', 'yaml', 'fusion', 'xlf', 'html', 'md']);
        $this->searchAndReplace('codeq/linkchecker', 'neosidekick/linkchecker', ['json', 'md']);
        $this->searchAndReplace('codeq.linkchecker', 'neosidekick.linkchecker', ['php', 'yaml', 'md']);
        $this->searchAndReplace('codeq_linkchecker', 'neosidekick_linkchecker', ['php', 'yaml']);

        $this->moveSettingsPaths('CodeQ.LinkChecker', 'NEOSidekick.LinkChecker');

        $this->showNote('The Doctrine migration NEOSidekick.LinkChecker Version20260618120000 renames the result table from codeq_linkchecker_domain_model_resultitem to neosidekick_linkchecker_domain_model_resultitem. Run doctrine:migrate after upgrading.');
    }
}
