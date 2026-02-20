<?php

namespace Drupal\deploy_trigger\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\deploy_trigger\Service\VercelTriggerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class VercelController extends ControllerBase
{
  /**
   * The Vercel trigger service.
   *
   * @var \Drupal\deploy_trigger\Service\VercelTriggerService
   */
  protected VercelTriggerService $vercelTrigger;

  /**
   * Constructs a VercelController object.
   *
   * @param \Drupal\deploy_trigger\Service\VercelTriggerService $vercel_trigger
   *   The Vercel trigger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    VercelTriggerService $vercel_trigger,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->vercelTrigger = $vercel_trigger;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('deploy_trigger.vercel_trigger'),
      $container->get('config.factory'),
    );
  }

  /**
   * Triggers a Vercel deployment via AJAX.
   */
  public function trigger($project_index): JsonResponse
  {
    $config = $this->config('deploy_trigger.settings');
    $projects = $config->get('vercel_projects') ?? [];

    if (!isset($projects[$project_index])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Project not found'], 404);
    }

    $project = $projects[$project_index];
    $result = $this->vercelTrigger->triggerProject($project);
    $label = $project['label'] ?: ('Vercel project ' . $project_index);

    if ($result['success']) {
      return new JsonResponse(
        ['success' => TRUE, 'message' => (string) $this->t('Vercel deploy triggered successfully for @project.', ['@project' => $label])],
        200,
        ['Cache-Control' => 'no-cache, no-store, must-revalidate']
      );
    }

    return new JsonResponse([
      'success' => FALSE,
      'message' => (string) $this->t('Failed to trigger Vercel deploy for @project. Please contact an administrator.', ['@project' => $label]),
    ], 500, ['Cache-Control' => 'no-cache, no-store, must-revalidate']);
  }

  /**
   * Cancels a Vercel deployment via AJAX.
   */
  public function cancel($project_index, $deployment_id): JsonResponse
  {
    $config = $this->config('deploy_trigger.settings');
    $projects = $config->get('vercel_projects') ?? [];

    if (!isset($projects[$project_index])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Project not found'], 404);
    }

    $project = $projects[$project_index];
    $result = $this->vercelTrigger->cancelDeployment($project, $deployment_id);

    $headers = ['Cache-Control' => 'no-cache, no-store, must-revalidate'];

    if ($result['success']) {
      return new JsonResponse(['success' => TRUE, 'message' => (string) $this->t('Deployment cancelled.')], 200, $headers);
    }

    $reason = $result['reason'] ?? 'error';
    if ($reason === 'forbidden') {
      $message = (string) $this->t('Token does not have permission to cancel deployments.');
    }
    elseif ($reason === 'not_found') {
      $message = (string) $this->t('Deployment not found.');
    }
    else {
      $message = (string) $this->t('Failed to cancel deployment.');
    }

    return new JsonResponse(['success' => FALSE, 'message' => $message], 200, $headers);
  }

  /**
   * Returns Vercel deployment status as JSON.
   */
  public function getStatus($project_index): JsonResponse
  {
    $config = $this->config('deploy_trigger.settings');
    $projects = $config->get('vercel_projects') ?? [];

    if (!isset($projects[$project_index])) {
      return new JsonResponse(['error' => 'Project not found', 'runs' => []], 404);
    }

    $deployments = $this->vercelTrigger->getDeployments($projects[$project_index]);

    $formatted = [];
    foreach ($deployments as $deployment) {
      $formatted[] = [
        'id' => $deployment['uid'] ?? $deployment['id'] ?? '',
        'state' => $deployment['state'] ?? $deployment['readyState'] ?? 'UNKNOWN',
        'created_at' => date('c', intdiv($deployment['createdAt'] ?? 0, 1000)),
        'url' => !empty($deployment['url']) ? 'https://' . $deployment['url'] : '',
      ];
    }

    return new JsonResponse(
      ['runs' => $formatted],
      200,
      ['Cache-Control' => 'no-cache, no-store, must-revalidate']
    );
  }
}
