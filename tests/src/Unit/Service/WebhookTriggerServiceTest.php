<?php

namespace Drupal\Tests\github_webhook\Unit\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\github_webhook\Service\WebhookTriggerService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @coversDefaultClass \Drupal\github_webhook\Service\WebhookTriggerService
 * @group github_webhook
 */
class WebhookTriggerServiceTest extends UnitTestCase {

  /**
   * The mocked HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * The mocked module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $moduleHandler;

  /**
   * The service under test.
   *
   * @var \Drupal\github_webhook\Service\WebhookTriggerService
   */
  protected $service;

  /**
   * A sample repository configuration.
   *
   * @var array
   */
  protected array $sampleRepo;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);

    $this->loggerFactory->method('get')
      ->with('github_webhook')
      ->willReturn($this->logger);

    $this->service = new WebhookTriggerService(
      $this->httpClient,
      $this->loggerFactory,
      $this->moduleHandler,
    );

    $this->sampleRepo = [
      'owner' => 'test-owner',
      'repo' => 'test-repo',
      'event_type' => 'deploy',
      'token_source' => 'manual',
      'github_token' => 'ghp_test_token_123',
      'token_key' => '',
      'workflow_file' => '',
    ];
  }

  /**
   * @covers ::triggerRepository
   */
  public function testTriggerRepositorySuccess(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.github.com/repos/test-owner/test-repo/dispatches',
        $this->callback(function ($options) {
          return $options['headers']['Authorization'] === 'Bearer ghp_test_token_123'
            && $options['headers']['Accept'] === 'application/vnd.github+json'
            && $options['json']['event_type'] === 'deploy';
        }),
      );

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('triggered successfully'),
        $this->anything(),
      );

    $result = $this->service->triggerRepository($this->sampleRepo);
    $this->assertTrue($result);
  }

  /**
   * @covers ::triggerRepository
   */
  public function testTriggerRepositoryDefaultEventType(): void {
    $repo = $this->sampleRepo;
    unset($repo['event_type']);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        $this->anything(),
        $this->callback(function ($options) {
          return $options['json']['event_type'] === 'webhook';
        }),
      );

    $result = $this->service->triggerRepository($repo);
    $this->assertTrue($result);
  }

  /**
   * @covers ::triggerRepository
   */
  public function testTriggerRepositoryNoToken(): void {
    $repo = $this->sampleRepo;
    $repo['github_token'] = '';

    $this->httpClient->expects($this->never())
      ->method('request');

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('No valid token'),
        $this->anything(),
      );

    $result = $this->service->triggerRepository($repo);
    $this->assertFalse($result);
  }

  /**
   * @covers ::triggerRepository
   */
  public function testTriggerRepositoryClientException(): void {
    $request = new Request('POST', 'https://api.github.com');
    $response = new Response(403);
    $exception = new ClientException('Forbidden', $request, $response);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to trigger'),
        $this->anything(),
      );

    $result = $this->service->triggerRepository($this->sampleRepo);
    $this->assertFalse($result);
  }

  /**
   * @covers ::triggerRepository
   */
  public function testTriggerRepositoryRequestException(): void {
    $request = new Request('POST', 'https://api.github.com');
    $exception = new RequestException('Connection error', $request);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Request error'),
        $this->anything(),
      );

    $result = $this->service->triggerRepository($this->sampleRepo);
    $this->assertFalse($result);
  }

  /**
   * @covers ::triggerRepository
   */
  public function testTriggerRepositoryGenericException(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new \RuntimeException('Something broke'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Unexpected error'),
        $this->anything(),
      );

    $result = $this->service->triggerRepository($this->sampleRepo);
    $this->assertFalse($result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenManual(): void {
    $result = $this->service->resolveToken($this->sampleRepo);
    $this->assertEquals('ghp_test_token_123', $result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenManualDefault(): void {
    $repo = $this->sampleRepo;
    unset($repo['token_source']);

    $result = $this->service->resolveToken($repo);
    $this->assertEquals('ghp_test_token_123', $result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenManualEmpty(): void {
    $repo = $this->sampleRepo;
    $repo['github_token'] = '';

    $result = $this->service->resolveToken($repo);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenManualNull(): void {
    $repo = $this->sampleRepo;
    unset($repo['github_token']);

    $result = $this->service->resolveToken($repo);
    $this->assertNull($result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenKeyModuleSuccess(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('key')
      ->willReturn(TRUE);

    $keyMock = new class {
      public function getKeyValue(): string {
        return 'secret_from_key_module';
      }
    };

    $keyRepoMock = new class ($keyMock) {
      private $key;

      public function __construct($key) {
        $this->key = $key;
      }

      public function getKey(string $key_id) {
        if ($key_id === 'my_github_key') {
          return $this->key;
        }
        return NULL;
      }
    };

    $service = new WebhookTriggerService(
      $this->httpClient,
      $this->loggerFactory,
      $this->moduleHandler,
      $keyRepoMock,
    );

    $repo = $this->sampleRepo;
    $repo['token_source'] = 'key';
    $repo['token_key'] = 'my_github_key';

    $result = $service->resolveToken($repo);
    $this->assertEquals('secret_from_key_module', $result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenKeyModuleKeyNotFound(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('key')
      ->willReturn(TRUE);

    $keyRepoMock = new class {
      public function getKey(string $key_id) {
        return NULL;
      }
    };

    $service = new WebhookTriggerService(
      $this->httpClient,
      $this->loggerFactory,
      $this->moduleHandler,
      $keyRepoMock,
    );

    $repo = $this->sampleRepo;
    $repo['token_source'] = 'key';
    $repo['token_key'] = 'nonexistent_key';

    $result = $service->resolveToken($repo);
    $this->assertNull($result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenKeyModuleNotInstalled(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('key')
      ->willReturn(FALSE);

    $repo = $this->sampleRepo;
    $repo['token_source'] = 'key';
    $repo['token_key'] = 'my_github_key';

    // When Key module is not installed, falls back to manual token.
    $result = $this->service->resolveToken($repo);
    $this->assertEquals('ghp_test_token_123', $result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenKeyModuleEmptyKeyId(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('key')
      ->willReturn(TRUE);

    $keyRepoMock = new class {
      public function getKey(string $key_id) {
        return NULL;
      }
    };

    $service = new WebhookTriggerService(
      $this->httpClient,
      $this->loggerFactory,
      $this->moduleHandler,
      $keyRepoMock,
    );

    $repo = $this->sampleRepo;
    $repo['token_source'] = 'key';
    $repo['token_key'] = '';

    $result = $service->resolveToken($repo);
    $this->assertNull($result);
  }

  /**
   * @covers ::cancelWorkflowRun
   */
  public function testCancelWorkflowRunSuccess(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.github.com/repos/test-owner/test-repo/actions/runs/12345/cancel',
        $this->callback(function ($options) {
          return $options['headers']['Authorization'] === 'Bearer ghp_test_token_123';
        }),
      );

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('cancelled'),
        $this->anything(),
      );

    $result = $this->service->cancelWorkflowRun($this->sampleRepo, 12345);
    $this->assertIsArray($result);
    $this->assertTrue($result['success']);
  }

  /**
   * @covers ::cancelWorkflowRun
   */
  public function testCancelWorkflowRunNoToken(): void {
    $repo = $this->sampleRepo;
    $repo['github_token'] = '';

    $this->httpClient->expects($this->never())
      ->method('request');

    $result = $this->service->cancelWorkflowRun($repo, 12345);
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('error', $result['reason']);
  }

  /**
   * @covers ::cancelWorkflowRun
   */
  public function testCancelWorkflowRunForbidden(): void {
    $request = new Request('POST', 'https://api.github.com');
    $response = new Response(403);
    $exception = new ClientException('Forbidden', $request, $response);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $result = $this->service->cancelWorkflowRun($this->sampleRepo, 12345);
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('forbidden', $result['reason']);
  }

  /**
   * @covers ::cancelWorkflowRun
   */
  public function testCancelWorkflowRunConflict(): void {
    $request = new Request('POST', 'https://api.github.com');
    $response = new Response(409);
    $exception = new ClientException('Conflict', $request, $response);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $result = $this->service->cancelWorkflowRun($this->sampleRepo, 12345);
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('conflict', $result['reason']);
  }

  /**
   * @covers ::cancelWorkflowRun
   */
  public function testCancelWorkflowRunOtherClientException(): void {
    $request = new Request('POST', 'https://api.github.com');
    $response = new Response(500);
    $exception = new ClientException('Server error', $request, $response);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $result = $this->service->cancelWorkflowRun($this->sampleRepo, 12345);
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('error', $result['reason']);
  }

  /**
   * @covers ::cancelWorkflowRun
   */
  public function testCancelWorkflowRunGenericException(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new \RuntimeException('Network error'));

    $result = $this->service->cancelWorkflowRun($this->sampleRepo, 12345);
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('error', $result['reason']);
  }

  /**
   * @covers ::getWorkflowRuns
   */
  public function testGetWorkflowRunsSuccess(): void {
    $runsData = [
      'workflow_runs' => [
        ['id' => 1, 'status' => 'completed'],
        ['id' => 2, 'status' => 'in_progress'],
      ],
    ];

    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')
      ->willReturn(json_encode($runsData));

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')
      ->willReturn($stream);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://api.github.com/repos/test-owner/test-repo/actions/runs',
        $this->callback(function ($options) {
          return $options['query']['event'] === 'repository_dispatch'
            && $options['query']['per_page'] === 5;
        }),
      )
      ->willReturn($response);

    $result = $this->service->getWorkflowRuns($this->sampleRepo);
    $this->assertCount(2, $result);
    $this->assertEquals(1, $result[0]['id']);
    $this->assertEquals('completed', $result[0]['status']);
  }

  /**
   * @covers ::getWorkflowRuns
   */
  public function testGetWorkflowRunsWithWorkflowFile(): void {
    $runsData = [
      'workflow_runs' => [
        ['id' => 1, 'status' => 'completed'],
      ],
    ];

    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')
      ->willReturn(json_encode($runsData));

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')
      ->willReturn($stream);

    $repo = $this->sampleRepo;
    $repo['workflow_file'] = 'deploy.yml';

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://api.github.com/repos/test-owner/test-repo/actions/workflows/deploy.yml/runs',
        $this->anything(),
      )
      ->willReturn($response);

    $result = $this->service->getWorkflowRuns($repo);
    $this->assertCount(1, $result);
  }

  /**
   * @covers ::getWorkflowRuns
   */
  public function testGetWorkflowRunsCustomPerPage(): void {
    $runsData = ['workflow_runs' => []];

    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')
      ->willReturn(json_encode($runsData));

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')
      ->willReturn($stream);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        $this->anything(),
        $this->callback(function ($options) {
          return $options['query']['per_page'] === 10;
        }),
      )
      ->willReturn($response);

    $result = $this->service->getWorkflowRuns($this->sampleRepo, 10);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::getWorkflowRuns
   */
  public function testGetWorkflowRunsNoToken(): void {
    $repo = $this->sampleRepo;
    $repo['github_token'] = '';

    $this->httpClient->expects($this->never())
      ->method('request');

    $result = $this->service->getWorkflowRuns($repo);
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::getWorkflowRuns
   */
  public function testGetWorkflowRunsException(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new \RuntimeException('API error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to fetch workflow runs'),
        $this->anything(),
      );

    $result = $this->service->getWorkflowRuns($this->sampleRepo);
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::getWorkflowRuns
   */
  public function testGetWorkflowRunsMissingKeyInResponse(): void {
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')
      ->willReturn(json_encode(['other_key' => 'value']));

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')
      ->willReturn($stream);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willReturn($response);

    $result = $this->service->getWorkflowRuns($this->sampleRepo);
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

}
