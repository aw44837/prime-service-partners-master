<?php

namespace Drupal\psp_request_service\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Request Service form.
 */
final class RequestServiceCommands extends DrushCommands {

  /**
   * Installs (or replaces) one of this module's webforms.
   */
  #[CLI\Command(name: 'psp-request-service:reset-form', aliases: ['psp-rs-reset'])]
  #[CLI\Option(name: 'force', description: 'Replace the form even if it already has submissions.')]
  #[CLI\Option(name: 'form', description: 'Webform to install: request_service (default) or book_online.')]
  #[CLI\Usage(name: 'drush psp-request-service:reset-form', description: 'Install the module\'s Request Service form, replacing an old one with no submissions.')]
  #[CLI\Usage(name: 'drush psp-request-service:reset-form --form=book_online', description: 'Install the Book Online form (service + date first) used by the booking card.')]
  public function resetForm(array $options = ['force' => FALSE, 'form' => 'request_service']): void {
    $id = (string) $options['form'];
    $count = \Drupal::service('psp_request_service.configurator')->resetForm((bool) $options['force'], $id);
    if ($count && !$options['force']) {
      $this->logger()->error(dt('@id has @n submission(s); not replaced. Re-run with --force to replace it anyway (submissions are kept but may not match the new fields).', ['@id' => $id, '@n' => $count]));
      return;
    }
    $this->logger()->success(dt('@id installed from psp_request_service and configured.', ['@id' => $id]));
  }

  /**
   * Re-applies the settings (brand, lead source, zips) to the forms.
   */
  #[CLI\Command(name: 'psp-request-service:apply', aliases: ['psp-rs-apply'])]
  public function apply(): void {
    \Drupal::service('psp_request_service.configurator')->apply()
      ? $this->logger()->success(dt('Settings applied to request_service / book_online.'))
      : $this->logger()->error(dt('No request_service or book_online webform on this site.'));
  }

}
