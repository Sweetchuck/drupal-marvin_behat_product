<?php

declare(strict_types = 1);

namespace Drush\Commands\marvin_behat_product;

use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\marvin\ComposerInfo;
use Drupal\marvin\Utils as MarvinUtils;
use Drush\Attributes as CLI;
use Drush\Commands\marvin\CommandsBase;
use Robo\Collection\CollectionBuilder;
use Robo\State\Data as RoboState;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class OnboardingCommands extends CommandsBase {

  use GitTaskLoader;

  protected Filesystem $fs;

  public function __construct(?ComposerInfo $composerInfo = NULL, ?Filesystem $fs = NULL) {
    parent::__construct($composerInfo);
    $this->fs = $fs ?: new Filesystem();
  }

  /**
   * @phpstan-return array<string, marvin-task-definition>
   */
  #[CLI\Hook(
    type: HookManager::ON_EVENT,
    target: 'marvin:onboarding',
  )]
  public function onEventMarvinOnboarding(): array {
    return $this->getTaskDefsOnboardingBehatLocalYml();
  }

  /**
   * @phpstan-return array<string, marvin-task-definition>
   */
  protected function getTaskDefsOnboardingBehatLocalYml(): array {
    $composerInfo = $this->getComposerInfo();
    $projectRoot = Path::getDirectory($composerInfo->getJsonFileName());
    if (!$this->isDeveloperMode($projectRoot)) {
      return [];
    }

    $taskForEach = $this->taskForEach();

    return [
      'marvin_behat_product.collect_behat_yml' => [
        'weight' => 110,
        'task' => $this
          ->taskGitListFiles()
          ->setPaths(['behat.yml', '*/behat.yml'])
          ->setAssetNamePrefix('marvin_behat_product.'),
      ],
      'marvin_behat_product.create_behat_RTE_yml' => [
        'weight' => 111,
        'task' => $taskForEach
          ->deferTaskConfiguration('setIterable', 'marvin_behat_product.files')
          ->withBuilder(function (CollectionBuilder $builder, string $baseFileName) use ($taskForEach) : void {
            $builder->addCode($this->getTaskOnboardingBehatLocalYmlSingle(
              $taskForEach->getState(),
              $baseFileName,
            ));
          }),
      ],
    ];
  }

  /**
   * @phpstan-param marvin-runtime-environment $runtimeEnvironment
   */
  protected function getTaskOnboardingBehatLocalYmlSingle(
    RoboState $state,
    string $behatFilePath,
  ): \Closure {
    return function () use ($state, $behatFilePath): int {
      $behatDir = Path::getDirectory($behatFilePath);
      if ($behatDir === '') {
        $behatDir = '.';
      }

      $rte = $state['runtimeEnvironment'];

      $exampleFilePath = "$behatDir/behat.local.example.yml";
      $exampleFileContent = $this->fs->exists($exampleFilePath) ?
        MarvinUtils::fileGetContents($exampleFilePath)
        : $this->getLocalFileContent();

      $localFilePath = "$behatDir/behat.{$rte['id']}.yml";
      $localFileContent = $this->fs->exists($localFilePath) ?
        MarvinUtils::fileGetContents($localFilePath)
        : $exampleFileContent;

      $uri = $state['primaryUri'];
      // @todo This is not bullet proof.
      $localFileContent = preg_replace(
        '/(?<=\n {6}base_url:).*?(?=\n)/u',
        ' ' . MarvinUtils::escapeYamlValueString($uri),
        $localFileContent,
      );

      if ($rte['id'] === 'host') {
        $localFileContent = preg_replace(
          '/(?<=\n {12}api_url:).*?(?=\n)/u',
          ' ' . MarvinUtils::escapeYamlValueString('http://127.0.0.1:9222'),
          $localFileContent,
        );
      }

      $this->fs->dumpFile($localFilePath, $localFileContent);
      $this->getLogger()->info(
        'File "<info>{filePath}</info>" created',
        [
          'filePath' => $localFilePath,
        ],
      );

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
