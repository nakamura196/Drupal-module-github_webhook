<?php

namespace Drupal\github_webhook\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class StatusController extends ControllerBase
{
  /**
   * Returns workflow run status as JSON.
   */
  public function getStatus($repo_index)
  {
    $config = \Drupal::config('github_webhook.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['error' => 'Repository not found', 'runs' => []], 404);
    }

    /** @var \Drupal\github_webhook\Service\WebhookTriggerService $trigger_service */
    $trigger_service = \Drupal::service('github_webhook.trigger');
    $runs = $trigger_service->getWorkflowRuns($repositories[$repo_index]);

    $formatted = [];
    foreach ($runs as $run) {
      $formatted[] = [
        'id' => $run['id'],
        'status' => $run['status'],
        'conclusion' => $run['conclusion'] ?? NULL,
        'created_at' => $run['created_at'],
        'updated_at' => $run['updated_at'],
        'html_url' => $run['html_url'],
      ];
    }

    return new JsonResponse(
      ['runs' => $formatted],
      200,
      ['Cache-Control' => 'no-cache, no-store, must-revalidate']
    );
  }
}
