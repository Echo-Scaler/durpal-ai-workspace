<?php

namespace Drupal\Tests\ai_provider_openai\Unit\Traits\OpenAi;

use Drupal\ai\Entity\AiFileInterface;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Traits\OpenAi\FileApiTrait;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenAI\Client;
use OpenAI\Testing\Responses\Fixtures\Files\CreateResponseFixture;
use OpenAI\Testing\Responses\Fixtures\Files\DeleteResponseFixture;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileApiTrait.
 *
 * Uses a real \OpenAI\Client backed by Guzzle's MockHandler so the `final`
 * class constraint on getClient(): Client is satisfied without needing to
 * mock the Client itself.
 *
 * @group ai_provider_openai
 * @covers \Drupal\ai\Traits\OpenAi\FileApiTrait
 */
class FileApiTraitTest extends TestCase {

  /**
   * The Guzzle mock handler, used to queue HTTP responses.
   */
  private MockHandler $httpMock;

  /**
   * The trait subject bound to a concrete anonymous class.
   */
  private object $subject;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->buildSubject([]);
  }

  /**
   * Builds (or rebuilds) the test subject with a fresh mock handler.
   *
   * @param array $responses
   *   Guzzle Response objects to queue.
   */
  private function buildSubject(array $responses): void {
    $this->httpMock = new MockHandler($responses);
    $stack = HandlerStack::create($this->httpMock);
    $guzzle = new GuzzleClient(['handler' => $stack]);
    $client = \OpenAI::factory()->withApiKey('test-key')->withHttpClient($guzzle)->make();

    $this->subject = new class($client) {
      use FileApiTrait;

      public function __construct(private readonly Client $client) {}

      /**
       * {@inheritdoc}
       */
      protected function getClient(): Client {
        return $this->client;
      }

    };
  }

  /**
   * Tests that batch purpose only accepts text and JSON types.
   */
  public function testSupportsMimeTypeBatchAcceptsTextAndJson(): void {
    $this->assertTrue($this->subject->supportsMimeType('text/plain', AiFileInterface::PURPOSE_BATCH));
    $this->assertTrue($this->subject->supportsMimeType('application/json', AiFileInterface::PURPOSE_BATCH));
    $this->assertTrue($this->subject->supportsMimeType('application/jsonl', AiFileInterface::PURPOSE_BATCH));
    $this->assertFalse($this->subject->supportsMimeType('application/pdf', AiFileInterface::PURPOSE_BATCH));
  }

  /**
   * Tests that fine-tune purpose only accepts text and JSON types.
   */
  public function testSupportsMimeTypeFineTuneAcceptsTextAndJson(): void {
    $this->assertTrue($this->subject->supportsMimeType('text/plain', AiFileInterface::PURPOSE_FINE_TUNE));
    $this->assertFalse($this->subject->supportsMimeType('image/png', AiFileInterface::PURPOSE_FINE_TUNE));
  }

  /**
   * Tests that vision purpose only accepts image MIME types.
   */
  public function testSupportsMimeTypeVisionAcceptsImages(): void {
    foreach (['image/png', 'image/jpeg', 'image/jpg', 'image/bmp', 'image/gif', 'image/tiff'] as $mime) {
      $this->assertTrue(
        $this->subject->supportsMimeType($mime, AiFileInterface::PURPOSE_VISION),
        "Expected $mime to be supported for vision."
      );
    }
    $this->assertFalse($this->subject->supportsMimeType('application/pdf', AiFileInterface::PURPOSE_VISION));
    $this->assertFalse($this->subject->supportsMimeType('text/plain', AiFileInterface::PURPOSE_VISION));
  }

  /**
   * Tests that user_data purpose accepts any MIME type.
   */
  public function testSupportsMimeTypeUserDataAcceptsAnything(): void {
    $this->assertTrue($this->subject->supportsMimeType('application/pdf', AiFileInterface::PURPOSE_USER_DATA));
    $this->assertTrue($this->subject->supportsMimeType('video/mp4', AiFileInterface::PURPOSE_USER_DATA));
  }

  /**
   * Tests successful file upload sets remote ID and merges metadata.
   */
  public function testUploadFileSetsRemoteIdAndMergesMetadata(): void {
    $this->buildSubject([
      new Response(200, ['Content-Type' => 'application/json'], json_encode(CreateResponseFixture::ATTRIBUTES)),
    ]);

    $remoteId = CreateResponseFixture::ATTRIBUTES['id'];
    $remoteIdSet = NULL;
    $metadataSet = NULL;

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getMimeType')->willReturn('text/plain');
    $aiFile->method('getPurpose')->willReturn(AiFileInterface::PURPOSE_USER_DATA);
    $aiFile->method('getMetadata')->willReturn([]);
    $aiFile->expects($this->once())
      ->method('setRemoteId')
      ->willReturnCallback(function (string $id) use (&$remoteIdSet, $aiFile) {
        $remoteIdSet = $id;
        return $aiFile;
      });
    $aiFile->expects($this->once())
      ->method('mergeMetadata')
      ->willReturnCallback(function (array $meta) use (&$metadataSet, $aiFile) {
        $metadataSet = $meta;
        return $aiFile;
      });

    $result = $this->subject->uploadFile($aiFile, 'file-content');

    $this->assertSame($aiFile, $result);
    $this->assertEquals($remoteId, $remoteIdSet);
    $this->assertIsArray($metadataSet);
    $this->assertArrayHasKey('id', $metadataSet);
  }

  /**
   * Tests that uploading an unsupported MIME type throws RuntimeException.
   */
  public function testUploadFileThrowsForUnsupportedMimeType(): void {
    $this->expectException(\RuntimeException::class);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getMimeType')->willReturn('image/png');
    $aiFile->method('getPurpose')->willReturn(AiFileInterface::PURPOSE_BATCH);
    $aiFile->method('getMetadata')->willReturn([]);

    $this->subject->uploadFile($aiFile, 'file-content');
  }

  /**
   * Tests that a "Request too large" API error becomes AiRateLimitException.
   */
  public function testUploadFileThrowsRateLimitOnLargeRequest(): void {
    $this->expectException(AiRateLimitException::class);

    $this->buildSubject([
      new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['message' => 'Request too large', 'type' => 'invalid_request_error', 'code' => NULL],
      ])),
    ]);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getMimeType')->willReturn('text/plain');
    $aiFile->method('getPurpose')->willReturn(AiFileInterface::PURPOSE_USER_DATA);
    $aiFile->method('getMetadata')->willReturn([]);

    $this->subject->uploadFile($aiFile, 'file-content');
  }

  /**
   * Tests that a quota exceeded API error becomes AiQuotaException.
   */
  public function testUploadFileThrowsQuotaExceptionOnQuotaError(): void {
    $this->expectException(AiQuotaException::class);

    $this->buildSubject([
      new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => NULL],
      ])),
    ]);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getMimeType')->willReturn('text/plain');
    $aiFile->method('getPurpose')->willReturn(AiFileInterface::PURPOSE_USER_DATA);
    $aiFile->method('getMetadata')->willReturn([]);

    $this->subject->uploadFile($aiFile, 'file-content');
  }

  /**
   * Tests successful remote deletion returns TRUE.
   */
  public function testDeleteFileReturnsTrueOnSuccess(): void {
    $this->buildSubject([
      new Response(200, ['Content-Type' => 'application/json'], json_encode(DeleteResponseFixture::ATTRIBUTES)),
    ]);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getRemoteId')->willReturn('file-abc123');

    $this->assertTrue($this->subject->deleteFile($aiFile));
  }

  /**
   * Tests deletion with no remote ID skips the API call and returns TRUE.
   */
  public function testDeleteFileReturnsTrueWhenNoRemoteId(): void {
    // No response queued — any HTTP call would throw an OutOfBoundsException.
    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getRemoteId')->willReturn(NULL);

    $this->assertTrue($this->subject->deleteFile($aiFile));
    $this->assertCount(0, $this->httpMock, 'No HTTP request should have been made.');
  }

  /**
   * Tests that an API error during deletion returns FALSE (soft failure).
   */
  public function testDeleteFileReturnsFalseOnApiError(): void {
    $this->buildSubject([
      new Response(500, [], 'Internal Server Error'),
    ]);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getRemoteId')->willReturn('file-abc123');

    $this->assertFalse($this->subject->deleteFile($aiFile));
  }

  /**
   * Tests download without a destination returns raw content.
   */
  public function testDownloadFileReturnsRawContentWithoutDestination(): void {
    $this->buildSubject([
      new Response(200, [], 'raw file content'),
    ]);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getRemoteId')->willReturn('file-abc123');

    $result = $this->subject->downloadFile($aiFile);
    $this->assertEquals('raw file content', $result);
  }

  /**
   * Tests that downloading a file with no remote ID throws RuntimeException.
   */
  public function testDownloadFileThrowsWhenNoRemoteId(): void {
    $this->expectException(\RuntimeException::class);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getRemoteId')->willReturn(NULL);

    $this->subject->downloadFile($aiFile);
  }

  /**
   * Tests download to a file path writes content and returns the path.
   */
  public function testDownloadFileWritesToDestinationPath(): void {
    $this->buildSubject([
      new Response(200, [], 'file content'),
    ]);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getRemoteId')->willReturn('file-abc123');
    $aiFile->method('getFilename')->willReturn('example.txt');

    $destination = sys_get_temp_dir() . '/ai_provider_test_' . uniqid() . '.txt';

    try {
      $result = $this->subject->downloadFile($aiFile, $destination);
      $this->assertEquals($destination, $result);
      $this->assertEquals('file content', file_get_contents($destination));
    }
    finally {
      if (file_exists($destination)) {
        unlink($destination);
      }
    }
  }

  /**
   * Tests download with a directory destination appends the filename.
   */
  public function testDownloadFileAppendsFilenameWhenDestinationIsDirectory(): void {
    $this->buildSubject([
      new Response(200, [], 'dir file content'),
    ]);

    $aiFile = $this->createMock(AiFileInterface::class);
    $aiFile->method('getRemoteId')->willReturn('file-abc123');
    $aiFile->method('getFilename')->willReturn('report.pdf');

    $dir = sys_get_temp_dir();
    $expectedPath = $dir . DIRECTORY_SEPARATOR . 'report.pdf';

    try {
      $result = $this->subject->downloadFile($aiFile, $dir);
      $this->assertEquals($expectedPath, $result);
      $this->assertEquals('dir file content', file_get_contents($expectedPath));
    }
    finally {
      if (file_exists($expectedPath)) {
        unlink($expectedPath);
      }
    }
  }

}
