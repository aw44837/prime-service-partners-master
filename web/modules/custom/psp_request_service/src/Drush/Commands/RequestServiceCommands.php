<?php

namespace Drupal\psp_request_service\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Request Service form.
 */
final class RequestServiceCommands extends DrushCommands {

  /**
   * Installs (or replaces) the request_service webform from this module.
   */
  #[CLI\Command(name: 'psp-request-service:reset-form', aliases: ['psp-rs-reset'])]
  #[CLI\Option(name: 'force', description: 'Replace the form even if it already has submissions.')]
  #[CLI\Usage(name: 'drush psp-request-service:reset-form', description: 'Install the module\'s Request Service form, replacing an old one with no submissions.')]
  public function resetForm(array $options = ['force' => FALSE]): void {
    $count = \Drupal::service('psp_request_service.configurator')->resetForm((bool) $options['force']);
    if ($count && !$options['force']) {
      $this->logger()->error(dt('request_service has @n submission(s); not replaced. Re-run with --force to replace it anyway (submissions are kept but may not match the new fields).', ['@n' => $count]));
      return;
    }
    $this->logger()->success(dt('request_service installed from psp_request_service and configured.'));
  }

  /**
   * Re-applies the settings (brand, lead source, zips) to the form.
   */
  #[CLI\Command(name: 'psp-request-service:apply', aliases: ['psp-rs-apply'])]
  public function apply(): void {
    \Drupal::service('psp_request_service.configurator')->apply()
      ? $this->logger()->success(dt('Settings applied to request_service.'))
      : $this->logger()->error(dt('No request_service webform on this site.'));
  }

}
