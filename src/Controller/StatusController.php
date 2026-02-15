<?php

namespace Drupal\github_webhook\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\github_webhook\Service\WebhookTriggerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class StatusController extends ControllerBase
{
  /**
   * The webhook trigger service.
   *
   * @var \Drupal\github_webhook\Service\WebhookTriggerService
   */
  protected WebhookTriggerService $webhookTrigger;

  /**
   * Constructs a StatusController object.
   *
   * @param \Drupal\github_webhook\Service\WebhookTriggerService $webhook_trigger
   *   The webhook trigger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    WebhookTriggerService $webhook_trigger,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->webhookTrigger = $webhook_trigger;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('github_webhook.trigger'),
      $container->get('config.factory'),
    );
  }

  /**
   * Triggers a webhook via AJAX.
   */
  public function trigger($repo_index): JsonResponse
  {
    $config = $this->config('github_webhook.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Repository not found'], 404);
    }

    $repository = $repositories[$repo_index];

    $success = $this->webhookTrigger->triggerRepository($repository);

    $label = $repository['owner'] . '/' . $repository['repo'];

    if ($success) {
      $data = ['success' => TRUE, 'message' => (string) $this->t('GitHub webhook triggered successfully for @repository.', ['@repository' => $label])];
      if ($this->currentUser()->hasPermission('administer github webhook')) {
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
  public function cancel($repo_index, $run_id): JsonResponse
  {
    $config = $this->config('github_webhook.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Repository not found'], 404);
    }

    $repository = $repositories[$repo_index];

    $result = $this->webhookTrigger->cancelWorkflowRun($repository, (int) $run_id);

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
  public function getStatus($repo_index): JsonResponse
  {
    $config = $this->config('github_webhook.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['error' => 'Repository not found', 'runs' => []], 404);
    }

    $runs = $this->webhookTrigger->getWorkflowRuns($repositories[$repo_index]);

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
