<?php

declare(strict_types = 1);

namespace Drush\Commands\marvin_behat_product;

use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\marvin\ComposerInfo;
use Drupal\marvin\Robo\SymlinkUpsertTaskLoader;
use Drush\Attributes as CLI;
use Drush\Commands\marvin\CommandsBase;
use Robo\Collection\CollectionBuilder;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class RuntimeEnvironmentCommands extends CommandsBase {

  use GitTaskLoader;
  use SymlinkUpsertTaskLoader;

  protected Filesystem $fs;

  public function __construct(?ComposerInfo $composerInfo = NULL, ?Filesystem $fs = NULL) {
    parent::__construct($composerInfo);
    $this->fs = $fs ?: new Filesystem();
  }

  /**
   * @phpstan-param marvin-runtime-environment $runtimeEnvironment
   */
  #[CLI\Hook(
    type: HookManager::ON_EVENT,
    target: 'marvin:runtime-environment:switch',
  )]
  public function onEventMarvinRuntimeEnvironmentSwitch(
    InputInterface $input,
    OutputInterface $output,
    array $runtimeEnvironment,
  ): array {
    return $this->getTaskDefsRuntimeEnvironmentSwitch($runtimeEnvironment);
  }

  /**
   * @phpstan-param marvin-runtime-environment $runtimeEnvironment
   *
   * @phpstan-return array<string, marvin-task-definition>
   */
  protected function getTaskDefsRuntimeEnvironmentSwitch(array $runtimeEnvironment): array {
    $composerInfo = $this->getComposerInfo();
    $projectRoot = Path::getDirectory($composerInfo->getJsonFileName());
    if (!$this->isDeveloperMode($projectRoot)) {
      return [];
    }

    $taskForEach = $this->taskForEach();

    return [
      'marvin_behat_product.behat_yml.collect' => [
        'weight' => 110,
        'task' => $this
          // @todo DRY \Drush\Commands\marvin_behat_product\BehatCommands::getTaskBehatConfigFinder.
          ->taskGitListFiles()
          ->setPaths(['behat.yml', '*/behat.yml'])
          ->setAssetNamePrefix('marvin_behat_product.'),
      ],
      'marvin_behat_product.behat_local_yml.symlink_update' => [
        'weight' => 111,
        'task' => $taskForEach
          ->deferTaskConfiguration('setIterable', 'marvin_behat_product.files')
          ->withBuilder(function (CollectionBuilder $builder, string $behatFilePath) use ($runtimeEnvironment) : void {
            $behatDir = Path::getDirectory($behatFilePath);
            if ($behatDir === '') {
              $behatDir = '.';
            }

            $builder->addTask(
              $this
                ->taskMarvinSymlinkUpsert()
                ->setActionOnSourceNotExists('delete')
                ->setSymlinkName("$behatDir/behat.local.yml")
                ->setSymlinkSrc("$behatDir/behat.{$runtimeEnvironment['id']}.yml")
                ->setSymlinkDst("behat.{$runtimeEnvironment['id']}.yml")
            );
          }),
      ],
    ];
  }

  protected function isDeveloperMode(string $projectRoot): bool {
    // @todo Read the tests dir path from configuration.
    return $this->fs->exists("$projectRoot/tests");
  }

}
