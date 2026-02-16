<?php

namespace Drupal\deploy_trigger\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\deploy_trigger\Service\GitHubTriggerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class GitHubController extends ControllerBase
{
  /**
   * The GitHub trigger service.
   *
   * @var \Drupal\deploy_trigger\Service\GitHubTriggerService
   */
  protected GitHubTriggerService $githubTrigger;

  /**
   * Constructs a GitHubController object.
   *
   * @param \Drupal\deploy_trigger\Service\GitHubTriggerService $github_trigger
   *   The GitHub trigger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    GitHubTriggerService $github_trigger,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->githubTrigger = $github_trigger;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('deploy_trigger.github_trigger'),
      $container->get('config.factory'),
    );
  }

  /**
   * Triggers a webhook via AJAX.
   */
  public function trigger($repo_index): JsonResponse
  {
    $config = $this->config('deploy_trigger.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Repository not found'], 404);
    }

    $repository = $repositories[$repo_index];

    $success = $this->githubTrigger->triggerRepository($repository);

    $label = $repository['owner'] . '/' . $repository['repo'];

    if ($success) {
      $data = ['success' => TRUE, 'message' => (string) $this->t('GitHub webhook triggered successfully for @repository.', ['@repository' => $label])];
      if ($this->currentUser()->hasPermission('administer deploy trigger')) {
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
    $config = $this->config('deploy_trigger.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Repository not found'], 404);
    }

    $repository = $repositories[$repo_index];

    $result = $this->githubTrigger->cancelWorkflowRun($repository, (int) $run_id);

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
    $config = $this->config('deploy_trigger.settings');
    $repositories = $config->get('repositories') ?? [];

    if (!isset($repositories[$repo_index])) {
      return new JsonResponse(['error' => 'Repository not found', 'runs' => []], 404);
    }

    $runs = $this->githubTrigger->getWorkflowRuns($repositories[$repo_index]);

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
