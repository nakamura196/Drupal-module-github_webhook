<?php

namespace Drupal\deploy_trigger\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for triggering Vercel deployments via Deploy Hooks.
 */
class VercelTriggerService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The Key repository service (optional, requires Key module).
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  protected $keyRepository;

  /**
   * Constructs a VercelTriggerService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param mixed $key_repository
   *   The Key repository service, or NULL if Key module is not installed.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    ModuleHandlerInterface $module_handler,
    $key_repository = NULL,
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('deploy_trigger');
    $this->moduleHandler = $module_handler;
    $this->keyRepository = $key_repository;
  }

  /**
   * Triggers a Vercel deployment via Deploy Hook URL.
   *
   * @param array $project
   *   A project configuration array with key: deploy_hook_url.
   *
   * @return array
   *   An array with 'success' key.
   */
  public function triggerProject(array $project): array {
    $deploy_hook_url = $project['deploy_hook_url'] ?? '';
    if (empty($deploy_hook_url)) {
      $this->logger->error('No Deploy Hook URL configured for Vercel project @label.', [
        '@label' => $project['label'] ?? 'unknown',
      ]);
      return ['success' => FALSE];
    }

    try {
      $this->httpClient->request('POST', $deploy_hook_url, [
        'timeout' => 30,
      ]);

      $this->logger->info('Vercel deploy triggered successfully for @label.', [
        '@label' => $project['label'] ?? 'unknown',
      ]);
      return ['success' => TRUE];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to trigger Vercel deploy for @label: @error', [
        '@label' => $project['label'] ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      return ['success' => FALSE];
    }
  }

  /**
   * Fetches recent deployments for a Vercel project.
   *
   * @param array $project
   *   The project configuration array.
   * @param int $limit
   *   Number of deployments to fetch.
   *
   * @return array
   *   Array of deployment data, or empty array on failure.
   */
  public function getDeployments(array $project, int $limit = 5): array {
    $token = $this->resolveToken($project);
    if (empty($token)) {
      return [];
    }

    $project_id = $project['project_id'] ?? '';
    if (empty($project_id)) {
      return [];
    }

    $url = 'https://api.vercel.com/v6/deployments';

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
        ],
        'query' => [
          'projectId' => $project_id,
          'limit' => $limit,
        ],
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['deployments'] ?? [];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch Vercel deployments for @label: @error', [
        '@label' => $project['label'] ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Cancels a Vercel deployment.
   *
   * @param array $project
   *   The project configuration array.
   * @param string $deployment_id
   *   The deployment ID to cancel (e.g. dpl_xxx).
   *
   * @return array
   *   An array with 'success' key, and optionally 'reason' on failure.
   */
  public function cancelDeployment(array $project, string $deployment_id): array {
    $token = $this->resolveToken($project);
    if (empty($token)) {
      return ['success' => FALSE, 'reason' => 'error'];
    }

    $url = 'https://api.vercel.com/v12/deployments/' . $deployment_id . '/cancel';

    try {
      $this->httpClient->request('PATCH', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
        ],
        'timeout' => 30,
      ]);

      $this->logger->info('Vercel deployment @id cancelled for @label.', [
        '@id' => $deployment_id,
        '@label' => $project['label'] ?? 'unknown',
      ]);
      return ['success' => TRUE];
    }
    catch (ClientException $e) {
      $code = $e->getResponse()->getStatusCode();
      $this->logger->error('Failed to cancel Vercel deployment @id for @label: @error', [
        '@id' => $deployment_id,
        '@label' => $project['label'] ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      if ($code === 403) {
        return ['success' => FALSE, 'reason' => 'forbidden'];
      }
      if ($code === 404) {
        return ['success' => FALSE, 'reason' => 'not_found'];
      }
      return ['success' => FALSE, 'reason' => 'error'];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cancel Vercel deployment @id for @label: @error', [
        '@id' => $deployment_id,
        '@label' => $project['label'] ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      return ['success' => FALSE, 'reason' => 'error'];
    }
  }

  /**
   * Resolves the Vercel API token for a project configuration.
   *
   * @param array $project
   *   The project configuration array.
   *
   * @return string|null
   *   The resolved token, or NULL if not found.
   */
  public function resolveToken(array $project): ?string {
    $token_source = $project['token_source'] ?? 'manual';

    if ($token_source === 'key' && $this->moduleHandler->moduleExists('key') && $this->keyRepository) {
      $key_id = $project['token_key'] ?? '';
      if ($key_id) {
        $key = $this->keyRepository->getKey($key_id);
        if ($key) {
          return $key->getKeyValue();
        }
      }
      return NULL;
    }

    return $project['vercel_token'] ?? NULL;
  }

}
