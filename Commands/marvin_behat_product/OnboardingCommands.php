<?php

declare(strict_types = 1);

namespace Drush\Commands\marvin_behat_product;

use Drupal\marvin\ComposerInfo;
use Drupal\marvin\Utils as MarvinUtils;
use Drush\Commands\marvin\CommandsBase;
use Robo\Collection\CollectionBuilder;
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
   * @hook on-event marvin:onboarding
   */
  public function onEventMarvinOnboarding(): array {
    return $this->getTaskDefsOnboardingBehatLocalYml();
  }

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
          ->setAssetNamePrefix('marvin_behat_product.')
      ],
      'marvin_behat_product.create_behat_RTE_yml' => [
        'weight' => 111,
        'task' => $taskForEach
          ->deferTaskConfiguration('setIterable', 'marvin_behat_product.files')
          ->withBuilder(function (CollectionBuilder $builder, string $baseFileName) use ($taskForEach) : void {
            $state = $taskForEach->getState();
            $runtimeEnvironments = $state['runtime_environments'] ?? [];
            $builder->addCode($this->getTaskOnboardingBehatLocalYmlSingle($baseFileName, $runtimeEnvironments));
          }),
      ],
    ];
  }

  protected function getTaskOnboardingBehatLocalYmlSingle(string $baseFileName, array $runtimeEnvironments): \Closure {
    return function () use ($baseFileName, $runtimeEnvironments): int {
      $logger = $this->getLogger();
      if (!$runtimeEnvironments) {
        // @todo Log message.
        return 0;
      }

      $behatDir = Path::getDirectory($baseFileName);
      if ($behatDir === '') {
        $behatDir = '.';
      }

      $exampleFileName = "$behatDir/behat.local.example.yml";
      $exampleFileContent = $this->fs->exists($exampleFileName) ?
        MarvinUtils::fileGetContents($exampleFileName)
        : $this->getLocalFileContent();


      $localFileContent = $this->fs->exists($exampleFileName) ?
        MarvinUtils::fileGetContents($exampleFileName)
        : $this->getLocalFileContent();

      $sites = $this->getConfig()->get('marvin.sites');
      foreach ($runtimeEnvironments as $rte) {
        $localFileName = "$behatDir/behat.{$rte['id']}.yml";
        $localFileContent = $this->fs->exists($localFileName) ?
          MarvinUtils::fileGetContents($localFileName)
          : $exampleFileContent;

        $firstSite = reset($sites);
        $uri = (string) reset($firstSite['uris']);
        // @todo This is not bullet proof.
        $localFileContent = preg_replace(
          '/(?<=\n {6}base_url:).*?(?=\n)/u',
          ' ' . MarvinUtils::escapeYamlValueString($uri),
          $localFileContent,
        );

        $this->fs->dumpFile($localFileName, $localFileContent);
        $logger->info(
          'File "<info>{fileName}</info>" created',
          [
            'fileName' => $localFileName,
          ],
        );
      }

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
