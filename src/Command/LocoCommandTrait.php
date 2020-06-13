<?php
namespace Loco\Command;

use Loco\Loco;
use Loco\LocoSystem;
use Loco\LocoVolume;
use Loco\Utils\Shell;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

trait LocoCommandTrait {

  public function configureSystemOptions() {
    $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Name of the loco.yml file');
    return $this;
  }

  public function initSystem(InputInterface $input, OutputInterface $output) {
    if (!$input->getOption('config')) {
      $input->setOption('config', $this->pickConfig());
    }
    if (!file_exists($input->getOption('config'))) {
      throw new \Exception("Failed to find loco config file: " . $input->getOption('config'));
    }
    $configFile = $input->getOption('config');
    $prjDir = dirname(dirname($input->getOption('config')));

    $output->writeln("<info>Parse configuration \"<comment>" . $configFile . "</comment>\"</info>", OutputInterface::VERBOSITY_VERBOSE);
    $settings = Yaml::parse(file_get_contents($configFile));

    $output->writeln("<info>Load local plugins</info>", OutputInterface::VERBOSITY_VERBOSE);
    if (!array_key_exists('plugins', $settings)) {
      $settings['plugins'] = ['.loco/plugin/*.php'];
    }
    Loco::filter('loco.config.plugins', [['file' => $configFile, 'prj' => $prjDir, 'config' => $settings]]);
    if (!empty($settings['plugins'])) {
      foreach ($settings['plugins'] as $pluginPathGlob) {
        Loco::plugins()->load($pluginPathGlob);
      }
    }

    $output->writeln("<info>Filter configuration</info>", OutputInterface::VERBOSITY_VERBOSE);
    $settings = Loco::filter('loco.config.filter', ['file' => $configFile, 'prj' => $prjDir, 'config' => $settings])['config'];
    return LocoSystem::create($prjDir, $settings);
  }

  public function pickConfig() {
    $c = Loco::filter('loco.config.find', ['file' => NULL]);
    if ($c['file']) {
      return $c['file'];
    }

    $parts = explode(DIRECTORY_SEPARATOR, Shell::getcwd());
    $suffix = ['.loco', 'loco.yml'];
    while ($parts) {
      $path = implode(DIRECTORY_SEPARATOR, array_merge($parts, $suffix));
      if (file_exists($path)) {
        return $path;
      }
      array_pop($parts);
    }
    return NULL;
  }

  /**
   * Determine the environment which applies for a given service-name.
   *
   * @param \Loco\LocoSystem $system
   * @param string|NULL $svcName
   *   The name of the service whose environment we want.
   *   If blank, then fallback to the shared system environment.
   * @return \Loco\LocoEnv
   */
  public function pickEnv($system, $svcName) {
    if (empty($svcName) || $svcName === '.') {
      return $system->createEnv();
    }
    elseif (isset($system->services[$svcName])) {
      return $system->services[$svcName]->createEnv();
    }
    else {
      throw new \RuntimeException("Unrecognized service: $svcName");
    }
  }

  /**
   * Determine which services may forced (overridden with new data).
   *
   * The "--force" option only applies to specifically identified services; excludes implicit dependencies.
   *
   * @param bool $isForce
   *   Whether the user requested "force".
   * @param array $requestedServices
   *   Services requested by the admin.
   * @param array $actualServices
   *   Total list of potential services (incl dependencies).
   *   Array(string $svcName => LocoService $service).
   * @return array
   *   Array(string $svcName).
   */
  protected function pickForceables($isForce, $requestedServices, $actualServices) {
    if (!$isForce) {
      return [];
    }
    elseif (empty($requestedServices)) {
      return array_diff(array_keys($actualServices), [LocoVolume::DEFAULT_NAME]);
    }
    else {
      return $requestedServices;
    }
  }

  /**
   * Get an ordered listed of services.
   *
   * @param \Loco\LocoSystem $system
   * @param array|NULL $svcNames
   *   List of services requested by the user.
   *   If blank, then all services.
   *   Ex: ['redis', 'mysql']
   *   Ex: ['redis,mysql']
   * @return array
   *   Array(string $name => LocoService $svc).
   *   List of service-objects, including both directly-requested
   *   services and indirect dependencies.
   *   The list is sorted based on dependencies.
   */
  public function pickServices($system, $svcNames) {
    // Expand any nested commas

    if (empty($svcNames)) {
      $svcNames = [];
      foreach ($system->services as $svcName => $svc) {
        if ($svc->enabled) {
          $svcNames[] = $svcName;
        }
      }
    }

    $svcs = [];
    foreach ($system->findDeps($svcNames) as $svcName) {
      $svcs[$svcName] = $system->services[$svcName];
    }
    return $svcs;
  }

  /**
   * @param array $words
   * @param string $style
   *   comment|info|error
   * @param string $delim
   * @return string
   */
  public function formatList($words, $style = 'comment', $delim = ' ') {
    return implode($delim, array_map(
      function($svcName) use ($style) {
        return "<$style>$svcName</$style>";
      },
      $words
    ));
  }

  protected function isEqualArray($a, $b) {
    sort($a);
    sort($b);
    return $a == $b;
  }

}
