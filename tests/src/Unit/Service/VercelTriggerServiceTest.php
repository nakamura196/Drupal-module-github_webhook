<?php

namespace Drupal\Tests\deploy_trigger\Unit\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\deploy_trigger\Service\VercelTriggerService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @coversDefaultClass \Drupal\deploy_trigger\Service\VercelTriggerService
 * @group deploy_trigger
 */
class VercelTriggerServiceTest extends UnitTestCase {

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
   * @var \Drupal\deploy_trigger\Service\VercelTriggerService
   */
  protected $service;

  /**
   * A sample project configuration.
   *
   * @var array
   */
  protected array $sampleProject;

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
      ->with('deploy_trigger')
      ->willReturn($this->logger);

    $this->service = new VercelTriggerService(
      $this->httpClient,
      $this->loggerFactory,
      $this->moduleHandler,
    );

    $this->sampleProject = [
      'label' => 'My Vercel Site',
      'deploy_hook_url' => 'https://api.vercel.com/v1/integrations/deploy/prj_xxx/hook_xxx',
      'project_id' => 'prj_test_123',
      'token_source' => 'manual',
      'vercel_token' => 'vercel_test_token_456',
      'token_key' => '',
    ];
  }

  /**
   * @covers ::triggerProject
   */
  public function testTriggerProjectSuccess(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.vercel.com/v1/integrations/deploy/prj_xxx/hook_xxx',
        $this->callback(function ($options) {
          return $options['timeout'] === 30;
        }),
      );

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('triggered successfully'),
        $this->anything(),
      );

    $result = $this->service->triggerProject($this->sampleProject);
    $this->assertTrue($result['success']);
  }

  /**
   * @covers ::triggerProject
   */
  public function testTriggerProjectNoHookUrl(): void {
    $project = $this->sampleProject;
    $project['deploy_hook_url'] = '';

    $this->httpClient->expects($this->never())
      ->method('request');

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('No Deploy Hook URL'),
        $this->anything(),
      );

    $result = $this->service->triggerProject($project);
    $this->assertFalse($result['success']);
  }

  /**
   * @covers ::triggerProject
   */
  public function testTriggerProjectException(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new \RuntimeException('Network error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to trigger'),
        $this->anything(),
      );

    $result = $this->service->triggerProject($this->sampleProject);
    $this->assertFalse($result['success']);
  }

  /**
   * @covers ::getDeployments
   */
  public function testGetDeploymentsSuccess(): void {
    $deploymentsData = [
      'deployments' => [
        ['uid' => 'dpl_1', 'readyState' => 'READY', 'createdAt' => 1700000000000],
        ['uid' => 'dpl_2', 'readyState' => 'BUILDING', 'createdAt' => 1700001000000],
      ],
    ];

    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')
      ->willReturn(json_encode($deploymentsData));

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')
      ->willReturn($stream);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://api.vercel.com/v6/deployments',
        $this->callback(function ($options) {
          return $options['headers']['Authorization'] === 'Bearer vercel_test_token_456'
            && $options['query']['projectId'] === 'prj_test_123'
            && $options['query']['limit'] === 5;
        }),
      )
      ->willReturn($response);

    $result = $this->service->getDeployments($this->sampleProject);
    $this->assertCount(2, $result);
    $this->assertEquals('dpl_1', $result[0]['uid']);
  }

  /**
   * @covers ::getDeployments
   */
  public function testGetDeploymentsNoToken(): void {
    $project = $this->sampleProject;
    $project['vercel_token'] = '';

    $this->httpClient->expects($this->never())
      ->method('request');

    $result = $this->service->getDeployments($project);
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::getDeployments
   */
  public function testGetDeploymentsNoProjectId(): void {
    $project = $this->sampleProject;
    $project['project_id'] = '';

    $this->httpClient->expects($this->never())
      ->method('request');

    $result = $this->service->getDeployments($project);
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::getDeployments
   */
  public function testGetDeploymentsException(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new \RuntimeException('API error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Failed to fetch Vercel deployments'),
        $this->anything(),
      );

    $result = $this->service->getDeployments($this->sampleProject);
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::getDeployments
   */
  public function testGetDeploymentsCustomLimit(): void {
    $deploymentsData = ['deployments' => []];

    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')
      ->willReturn(json_encode($deploymentsData));

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')
      ->willReturn($stream);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        $this->anything(),
        $this->callback(function ($options) {
          return $options['query']['limit'] === 10;
        }),
      )
      ->willReturn($response);

    $result = $this->service->getDeployments($this->sampleProject, 10);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::cancelDeployment
   */
  public function testCancelDeploymentSuccess(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->with(
        'PATCH',
        'https://api.vercel.com/v12/deployments/dpl_test123/cancel',
        $this->callback(function ($options) {
          return $options['headers']['Authorization'] === 'Bearer vercel_test_token_456';
        }),
      );

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('cancelled'),
        $this->anything(),
      );

    $result = $this->service->cancelDeployment($this->sampleProject, 'dpl_test123');
    $this->assertIsArray($result);
    $this->assertTrue($result['success']);
  }

  /**
   * @covers ::cancelDeployment
   */
  public function testCancelDeploymentNoToken(): void {
    $project = $this->sampleProject;
    $project['vercel_token'] = '';

    $this->httpClient->expects($this->never())
      ->method('request');

    $result = $this->service->cancelDeployment($project, 'dpl_test123');
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('error', $result['reason']);
  }

  /**
   * @covers ::cancelDeployment
   */
  public function testCancelDeploymentForbidden(): void {
    $request = new Request('PATCH', 'https://api.vercel.com');
    $response = new Response(403);
    $exception = new ClientException('Forbidden', $request, $response);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $result = $this->service->cancelDeployment($this->sampleProject, 'dpl_test123');
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('forbidden', $result['reason']);
  }

  /**
   * @covers ::cancelDeployment
   */
  public function testCancelDeploymentNotFound(): void {
    $request = new Request('PATCH', 'https://api.vercel.com');
    $response = new Response(404);
    $exception = new ClientException('Not Found', $request, $response);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException($exception);

    $result = $this->service->cancelDeployment($this->sampleProject, 'dpl_nonexistent');
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('not_found', $result['reason']);
  }

  /**
   * @covers ::cancelDeployment
   */
  public function testCancelDeploymentGenericException(): void {
    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(new \RuntimeException('Network error'));

    $result = $this->service->cancelDeployment($this->sampleProject, 'dpl_test123');
    $this->assertIsArray($result);
    $this->assertFalse($result['success']);
    $this->assertEquals('error', $result['reason']);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenManual(): void {
    $result = $this->service->resolveToken($this->sampleProject);
    $this->assertEquals('vercel_test_token_456', $result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenManualDefault(): void {
    $project = $this->sampleProject;
    unset($project['token_source']);

    $result = $this->service->resolveToken($project);
    $this->assertEquals('vercel_test_token_456', $result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenManualEmpty(): void {
    $project = $this->sampleProject;
    $project['vercel_token'] = '';

    $result = $this->service->resolveToken($project);
    $this->assertEmpty($result);
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
        return 'secret_vercel_token';
      }
    };

    $keyRepoMock = new class ($keyMock) {
      private $key;

      public function __construct($key) {
        $this->key = $key;
      }

      public function getKey(string $key_id) {
        if ($key_id === 'my_vercel_key') {
          return $this->key;
        }
        return NULL;
      }
    };

    $service = new VercelTriggerService(
      $this->httpClient,
      $this->loggerFactory,
      $this->moduleHandler,
      $keyRepoMock,
    );

    $project = $this->sampleProject;
    $project['token_source'] = 'key';
    $project['token_key'] = 'my_vercel_key';

    $result = $service->resolveToken($project);
    $this->assertEquals('secret_vercel_token', $result);
  }

  /**
   * @covers ::resolveToken
   */
  public function testResolveTokenKeyModuleNotInstalled(): void {
    $this->moduleHandler->method('moduleExists')
      ->with('key')
      ->willReturn(FALSE);

    $project = $this->sampleProject;
    $project['token_source'] = 'key';
    $project['token_key'] = 'my_vercel_key';

    // When Key module is not installed, falls back to manual token.
    $result = $this->service->resolveToken($project);
    $this->assertEquals('vercel_test_token_456', $result);
  }

}
