<?php

namespace Drupal\github_webhook\Service;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for triggering GitHub repository_dispatch webhooks.
 */
class WebhookTriggerService {

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
      \Drupal::logger('github_webhook')->error('No valid token found for @repository.', [
        '@repository' => $owner . '/' . $repo,
      ]);
      return FALSE;
    }

    $event_type = $repository['event_type'] ?? 'webhook';
    $client = \Drupal::httpClient();
    $url = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/dispatches';

    try {
      $client->request('POST', $url, [
        'headers' => [
          'Accept' => 'application/vnd.github+json',
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
          'X-GitHub-Api-Version' => '2022-11-28',
        ],
        'json' => ['event_type' => $event_type],
      ]);

      \Drupal::logger('github_webhook')->info('GitHub webhook triggered successfully for @repository.', [
        '@repository' => $owner . '/' . $repo,
      ]);
      return TRUE;
    }
    catch (ClientException $e) {
      \Drupal::logger('github_webhook')->error('Failed to trigger webhook for @repository: @error', [
        '@repository' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
    }
    catch (RequestException $e) {
      \Drupal::logger('github_webhook')->error('Request error for @repository: @error', [
        '@repository' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
    }
    catch (GuzzleException $e) {
      \Drupal::logger('github_webhook')->error('General error for @repository: @error', [
        '@repository' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('github_webhook')->error('Unexpected error for @repository: @error', [
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

    if ($token_source === 'key' && \Drupal::moduleHandler()->moduleExists('key')) {
      $key_id = $repository['token_key'] ?? '';
      if ($key_id) {
        $key = \Drupal::service('key.repository')->getKey($key_id);
        if ($key) {
          return $key->getKeyValue();
        }
      }
      return NULL;
    }

    return $repository['github_token'] ?? NULL;
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
    $client = \Drupal::httpClient();

    if (!empty($workflow_file)) {
      $url = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/actions/workflows/' . $workflow_file . '/runs';
    }
    else {
      $url = 'https://api.github.com/repos/' . $owner . '/' . $repo . '/actions/runs';
    }

    try {
      $response = $client->request('GET', $url, [
        'headers' => [
          'Accept' => 'application/vnd.github+json',
          'Authorization' => 'Bearer ' . $token,
          'X-GitHub-Api-Version' => '2022-11-28',
        ],
        'query' => [
          'event' => 'repository_dispatch',
          'per_page' => $perPage,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['workflow_runs'] ?? [];
    }
    catch (\Exception $e) {
      \Drupal::logger('github_webhook')->error('Failed to fetch workflow runs for @repo: @error', [
        '@repo' => $owner . '/' . $repo,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
