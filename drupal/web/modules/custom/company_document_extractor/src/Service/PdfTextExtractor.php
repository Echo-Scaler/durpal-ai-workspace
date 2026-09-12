<?php

namespace Drupal\company_document_extractor\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser;

/**
 * Service for extracting text from PDF files using smalot/pdfparser.
 */
class PdfTextExtractor {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a PdfTextExtractor object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(FileSystemInterface $file_system, LoggerInterface $logger) {
    $this->fileSystem = $file_system;
    $this->logger = $logger;
  }

  /**
   * Extracts text from a Drupal File entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   *
   * @return string
   *   The extracted plain text.
   */
  public function extractFromFile(FileInterface $file): string {
    $uri = $file->getFileUri();
    return $this->extractFromUri($uri);
  }

  /**
   * Extracts text from a file URI (e.g., private://documents/report.pdf).
   *
   * @param string $uri
   *   The stream URI.
   *
   * @return string
   *   The extracted plain text.
   */
  public function extractFromUri(string $uri): string {
    $realpath = $this->fileSystem->realpath($uri);
    if (!$realpath || !file_exists($realpath)) {
      $this->logger->error('File not found for extraction: @uri (realpath: @path)', [
        '@uri' => $uri,
        '@path' => $realpath ?: 'null',
      ]);
      return '';
    }

    return $this->extractFromPath($realpath);
  }

  /**
   * Extracts text from a local filesystem path.
   *
   * @param string $realpath
   *   Absolute path to the file.
   *
   * @return string
   *   The extracted plain text.
   */
  public function extractFromPath(string $realpath): string {
    if (!file_exists($realpath) || !is_readable($realpath)) {
      $this->logger->error('File not readable at path: @path', ['@path' => $realpath]);
      return '';
    }

    try {
      $parser = new Parser();
      $pdf = $parser->parseFile($realpath);
      $raw_text = $pdf->getText();

      return $this->sanitizeText($raw_text);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to parse PDF file @path: @message', [
        '@path' => $realpath,
        '@message' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Cleans and normalizes extracted text.
   *
   * @param string $text
   *   Raw text extracted from PDF.
   *
   * @return string
   *   Sanitized clean text.
   */
  public function sanitizeText(string $text): string {
    // Convert all newline variants to Unix \n.
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Strip non-printable control characters except standard tabs and newlines.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

    // Normalize multiple consecutive spaces or tabs into a single space on lines.
    $text = preg_replace('/[ \t]+/u', ' ', $text);

    // Collapse more than two consecutive newlines into two newlines.
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);

    return trim($text);
  }

}
