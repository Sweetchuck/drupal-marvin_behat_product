<?php

declare(strict_types = 1);

namespace Drush\Commands\marvin_behat_product;

use Drupal\marvin\ComposerInfo;
use Drush\Commands\marvin\CommandsBase;
use Robo\Collection\CollectionBuilder;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class RuntimeEnvironmentCommands extends CommandsBase {

  use GitTaskLoader;

  protected Filesystem $fs;

  public function __construct(?ComposerInfo $composerInfo = NULL, ?Filesystem $fs = NULL) {
    parent::__construct($composerInfo);
    $this->fs = $fs ?: new Filesystem();
  }

  /**
   * @hook on-event marvin:runtime-environment:switch
   */
  public function onEventMarvinRuntimeEnvironmentSwitch(
    InputInterface $input,
    OutputInterface $output,
    array $runtimeEnvironment,
  ): array {
    return $this->getTaskDefsRuntimeEnvironmentSwitch($runtimeEnvironment);
  }

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
          ->setAssetNamePrefix('marvin_behat_product.')
      ],
      'marvin_behat_product.behat_local_yml.symlink_update' => [
        'weight' => 111,
        'task' => $taskForEach
          ->deferTaskConfiguration('setIterable', 'marvin_behat_product.files')
          ->withBuilder(function (CollectionBuilder $builder, string $baseFileName) use ($taskForEach, $runtimeEnvironment) : void {
            // @todo Create native Robo task instead of callable.
            $builder->addCode($this->getTaskDefsRuntimeEnvironmentSwitchSingle($baseFileName, $runtimeEnvironment));
          }),
      ],
    ];
  }

  protected function getTaskDefsRuntimeEnvironmentSwitchSingle(string $baseFileName, array $runtimeEnvironment): \Closure {
    return function () use ($baseFileName, $runtimeEnvironment): int {
      $behatDir = Path::getDirectory($baseFileName);
      if ($behatDir === '') {
        $behatDir = '.';
      }

      $linkFileName = "behat.local.yml";
      $linkFilePath = "$behatDir/$linkFileName";

      $targetFileName = "behat.{$runtimeEnvironment['id']}.yml";
      $targetFilePath = "$behatDir/$targetFileName";

      $logger = $this->getLogger();
      $loggerArgs = [
        'linkFileName' => $linkFileName,
        'linkFilePath' => $linkFilePath,
        'targetFileName' => $targetFileName,
        'targetFilePath' => $targetFilePath,
      ];

      if ($this->fs->exists($linkFilePath) && !is_link($linkFilePath)) {
        $logger->error(
          'file {linkFilePath} should be a symlink but it is not',
          $loggerArgs,
        );

        return 0;
      }

      if (!$this->fs->exists($targetFilePath)) {
        $logger->error(
          'file {targetFilePath} is not exist; maybe the "marvin:onboarding" is not complete',
          $loggerArgs,
        );

        return 0;
      }

      $currentTarget = NULL;
      if ($this->fs->exists($linkFilePath)) {
        $currentTarget = $this->fs->readlink($linkFilePath);
      }

      if ($currentTarget) {
        if ($currentTarget === $targetFileName) {
          $logger->info(
            'no need to update {linkFilePath}',
            $loggerArgs,
          );

          return 0;
        }

        $this->fs->remove($linkFilePath);
      }

      $logger->info(
        'create symlink {linkFilePath} => {targetFileName}',
        $loggerArgs,
      );
      $this->fs->symlink($targetFileName, $linkFilePath);

      return 0;
    };
  }

  protected function isDeveloperMode(string $projectRoot): bool {
    // @todo Read the tests dir path from configuration.
    return $this->fs->exists("$projectRoot/tests");
  }

  protected function getLocalFileContent(): string {
    return <<<YAML
default:
  extensions:
    Drupal\MinkExtension:
      base_url: 'http://localhost'

YAML;
  }

}
