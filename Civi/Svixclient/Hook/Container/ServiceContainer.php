<?php

declare(strict_types=1);

namespace Civi\Svixclient\Hook\Container;

use Civi\Svixclient\Service\SvixWebhookMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Service container registration for Svix Client extension.
 *
 * @package Civi\Svixclient\Hook\Container
 */
class ServiceContainer {

  /**
   * The container builder instance.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerBuilder
   */
  private ContainerBuilder $container;

  /**
   * ServiceContainer constructor.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
   *   The container builder.
   */
  public function __construct(ContainerBuilder $container) {
    $this->container = $container;
  }

  /**
   * Registers services to container.
   */
  public function register(): void {
    // Svix webhook middleware - reusable verification service.
    $this->container->setDefinition('svix.webhook_middleware', new Definition(
      SvixWebhookMiddleware::class
    ))->setPublic(TRUE);

    // Set alias for autowiring.
    $this->container->setAlias(
      SvixWebhookMiddleware::class,
      'svix.webhook_middleware'
    );
  }

}
