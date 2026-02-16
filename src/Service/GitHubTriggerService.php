<?php

namespace Drupal\deploy_trigger\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for triggering GitHub repository_dispatch webhooks.
 */
class GitHubTriggerService {

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
   * Constructs a GitHubTriggerService object.
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
   * Triggers a repository_dispatch event for the given repository config.
   *
   * @param array $repository
   *   A repository configuration array with keys: owner, repo, event_type,
   *   token_source, github_token, token_key.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function triggerRepository(array $repository): bool {
    $owner = $repository['owner'];
    $repo = $repository['repo'];

    $token = $this->resolveToken($repository);
    if (empty($token)) {
      $this->logger->error('No valid token found for @repository.', [
        '@repository' => $owner . '/' . $repo,
      ]);
      return FALSE;
    }

    $event_type = $repository['event_type'] ?? 'webhook';
    $url = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/dispatches';

    try {
      $this->httpClient->request('POST', $url, [
        'headers' => [
          'Accept' => 'application/vnd.github+json',
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
          'X-GitHub-Api-Version' => '2022-11-28',
        ],
        'json' => ['event_type' => $event_type],
        'timeout' => 30,
      ]);

      $this->logger->info('GitHub webhook triggered successfully for @repository.', [
        '@repository' => $owner . '/' . $repo,
      ]);
      return TRUE;
    }
    catch (ClientException $e) {
      $this->logger->error('Failed to trigger webhook for @repository: @error', [
        '@repository' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
    }
    catch (RequestException $e) {
      $this->logger->error('Request error for @repository: @error', [
        '@repository' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('General error for @repository: @error', [
        '@repository' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error for @repository: @error', [
        '@repository' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * Resolves the GitHub token for a repository configuration.
   *
   * @param array $repository
   *   The repository configuration array.
   *
   * @return string|null
   *   The resolved token, or NULL if not found.
   */
  public function resolveToken(array $repository): ?string {
    $token_source = $repository['token_source'] ?? 'manual';

    if ($token_source === 'key' && $this->moduleHandler->moduleExists('key') && $this->keyRepository) {
      $key_id = $repository['token_key'] ?? '';
      if ($key_id) {
        $key = $this->keyRepository->getKey($key_id);
        if ($key) {
          return $key->getKeyValue();
        }
      }
      return NULL;
    }

    return $repository['github_token'] ?? NULL;
  }

  /**
   * Cancels a workflow run.
   *
   * @param array $repository
   *   The repository configuration array.
   * @param int $run_id
   *   The workflow run ID to cancel.
   *
   * @return array
   *   An array with 'success' key, and optionally 'reason' on failure.
   */
  public function cancelWorkflowRun(array $repository, int $run_id): array {
    $token = $this->resolveToken($repository);
    if (empty($token)) {
      return ['success' => FALSE, 'reason' => 'error'];
    }

    $owner = $repository['owner'];
    $repo = $repository['repo'];
    $url = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/actions/runs/' . $run_id . '/cancel';

    try {
      $this->httpClient->request('POST', $url, [
        'headers' => [
          'Accept' => 'application/vnd.github+json',
          'Authorization' => 'Bearer ' . $token,
          'X-GitHub-Api-Version' => '2022-11-28',
        ],
        'timeout' => 30,
      ]);

      $this->logger->info('Workflow run @run_id cancelled for @repository.', [
        '@run_id' => $run_id,
        '@repository' => $owner . '/' . $repo,
      ]);
      return ['success' => TRUE];
    }
    catch (ClientException $e) {
      $code = $e->getResponse()->getStatusCode();
      $this->logger->error('Failed to cancel workflow run @run_id for @repo: @error', [
        '@run_id' => $run_id,
        '@repo' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
      if ($code === 403) {
        return ['success' => FALSE, 'reason' => 'forbidden'];
      }
      if ($code === 409) {
        return ['success' => FALSE, 'reason' => 'conflict'];
      }
      return ['success' => FALSE, 'reason' => 'error'];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cancel workflow run @run_id for @repo: @error', [
        '@run_id' => $run_id,
        '@repo' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
      return ['success' => FALSE, 'reason' => 'error'];
    }
  }

  /**
   * Fetches recent workflow runs triggered by repository_dispatch.
   *
   * @param array $repository
   *   The repository configuration array.
   * @param int $perPage
   *   Number of runs to fetch.
   *
   * @return array
   *   Array of workflow run data, or empty array on failure.
   */
  public function getWorkflowRuns(array $repository, int $perPage = 5): array {
    $token = $this->resolveToken($repository);
    if (empty($token)) {
      return [];
    }

    $owner = $repository['owner'];
    $repo = $repository['repo'];
    $workflow_file = $repository['workflow_file'] ?? '';

    if (!empty($workflow_file)) {
      $url = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/actions/workflows/' . $workflow_file . '/runs';
    }
    else {
      $url = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/actions/runs';
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Accept' => 'application/vnd.github+json',
          'Authorization' => 'Bearer ' . $token,
          'X-GitHub-Api-Version' => '2022-11-28',
        ],
        'query' => [
          'event' => 'repository_dispatch',
          'per_page' => $perPage,
        ],
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['workflow_runs'] ?? [];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch workflow runs for @repo: @error', [
        '@repo' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
