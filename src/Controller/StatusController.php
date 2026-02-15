<?php

namespace Drupal\github_webhook\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class StatusController extends ControllerBase
{
  /**
   * Triggers a webhook via AJAX.
   */
  public function trigger($repo_index)
  {
    $config = \Drupal::config('github_webhook.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Repository not found'], 404);
    }

    $repository = $repositories[$repo_index];

    /** @var \Drupal\github_webhook\Service\WebhookTriggerService $trigger_service */
    $trigger_service = \Drupal::service('github_webhook.trigger');
    $success = $trigger_service->triggerRepository($repository);

    $label = $repository['owner'] . '/' . $repository['repo'];

    if ($success) {
      $data = ['success' => TRUE, 'message' => (string) $this->t('GitHub webhook triggered successfully for @repository.', ['@repository' => $label])];
      if (\Drupal::currentUser()->hasPermission('administer github webhook')) {
        $data['actions_url'] = 'https://github.com/' . $repository['owner'] . '/' . $repository['repo'] . '/actions';
      }
      return new JsonResponse($data, 200, ['Cache-Control' => 'no-cache, no-store, must-revalidate']);
    }

    return new JsonResponse([
      'success' => FALSE,
      'message' => (string) $this->t('Failed to trigger GitHub webhook for @repository. Please contact an administrator.', ['@repository' => $label]),
    ], 500, ['Cache-Control' => 'no-cache, no-store, must-revalidate']);
  }

  /**
   * Cancels a workflow run via AJAX.
   */
  public function cancel($repo_index, $run_id)
  {
    $config = \Drupal::config('github_webhook.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Repository not found'], 404);
    }

    $repository = $repositories[$repo_index];

    /** @var \Drupal\github_webhook\Service\WebhookTriggerService $trigger_service */
    $trigger_service = \Drupal::service('github_webhook.trigger');
    $result = $trigger_service->cancelWorkflowRun($repository, (int) $run_id);

    $headers = ['Cache-Control' => 'no-cache, no-store, must-revalidate'];

    if ($result['success']) {
      return new JsonResponse(['success' => TRUE, 'message' => (string) $this->t('Workflow run cancelled.')], 200, $headers);
    }

    $reason = $result['reason'] ?? 'error';
    if ($reason === 'forbidden') {
      $message = (string) $this->t('Token does not have permission to cancel workflow runs. Update the token with Actions read/write permission.');
    }
    elseif ($reason === 'conflict') {
      $message = (string) $this->t('This workflow run has already completed and cannot be cancelled.');
    }
    else {
      $message = (string) $this->t('Failed to cancel workflow run.');
    }

    return new JsonResponse(['success' => FALSE, 'message' => $message], 200, $headers);
  }

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
