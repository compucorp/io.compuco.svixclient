<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'svixclient.civix.php';
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}
// phpcs:enable

use Civi\Svixclient\Hook\Container\ServiceContainer;
use CRM_Svixclient_ExtensionUtil as E;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function svixclient_civicrm_config(\CRM_Core_Config $config): void {
  _svixclient_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_container().
 *
 * Registers Svix services with the dependency injection container.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container/
 */
function svixclient_civicrm_container(ContainerBuilder $container): void {
  $serviceContainer = new ServiceContainer($container);
  $serviceContainer->register();
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function svixclient_civicrm_install(): void {
  _svixclient_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function svixclient_civicrm_enable(): void {
  _svixclient_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_post().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_post
 */
function svixclient_civicrm_post(string $op, string $objectName, ?int $id, &$objectRef): void {
  if ($op === 'delete' && $objectName === 'SvixDestination') {
    $hook = new \Civi\Svixclient\Hook\Post\DeleteSvixDestination($id, $objectRef);
    $hook->run();
  }
}
