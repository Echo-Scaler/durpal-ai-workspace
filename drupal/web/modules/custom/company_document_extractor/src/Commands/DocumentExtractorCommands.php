<?php

declare(strict_types=1);

namespace Drupal\company_document_extractor\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\company_document_extractor\Service\PdfTextExtractor;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for extracting text from company documents.
 */
class DocumentExtractorCommands extends DrushCommands {

  /**
   * The PDF text extractor service.
   *
   * @var \Drupal\company_document_extractor\Service\PdfTextExtractor
   */
  protected PdfTextExtractor $pdfExtractor;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a DocumentExtractorCommands object.
   */
  public function __construct(PdfTextExtractor $pdf_extractor, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->pdfExtractor = $pdf_extractor;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Extracts and displays text from a PDF file, media document, or path.
   *
   * @param string $target
   *   The file ID, media ID, URI (private://...), or filesystem path.
   * @param array $options
   *   Command options.
   *
   * @command company-document:extract
   * @aliases doc:extract,doc-extract
   * @usage drush doc:extract 1 --type=media
   *   Extract text from Media entity ID 1.
   * @usage drush doc:extract private://documents/sample.pdf
   *   Extract text from a private file URI.
   * @usage drush doc:extract 1 --type=file
   *   Extract text from File entity ID 1.
   */
  #[CLI\Command(name: 'company-document:extract', aliases: ['doc:extract', 'doc-extract'])]
  #[CLI\Argument(name: 'target', description: 'The file ID, media ID, URI, or filesystem path')]
  #[CLI\Option(name: 'type', description: 'Target entity type: media, file, or auto (default)')]
  #[CLI\Option(name: 'raw', description: 'Output only the extracted text without headers')]
  #[CLI\Usage(name: 'drush doc:extract 1 --type=media', description: 'Extract text from Media entity ID 1.')]
  #[CLI\Usage(name: 'drush doc:extract private://documents/sample.pdf', description: 'Extract text from URI.')]
  public function extract(string $target, array $options = ['type' => 'auto', 'raw' => false]): void {
    $target_type = $options['type'] ?? 'auto';
    $raw_only = !empty($options['raw']);

    $extracted_text = '';
    $source_desc = '';

    // Handle URI or filesystem path.
    if (str_starts_with($target, 'private://') || str_starts_with($target, 'public://') || str_starts_with($target, '/')) {
      $source_desc = "Path/URI: $target";
      $extracted_text = str_starts_with($target, '/')
        ? $this->pdfExtractor->extractFromPath($target)
        : $this->pdfExtractor->extractFromUri($target);
    }
    // Handle numeric ID.
    elseif (is_numeric($target)) {
      $id = (int) $target;
      if ($target_type === 'file') {
        $file = $this->entityTypeManager->getStorage('file')->load($id);
        if ($file instanceof FileInterface) {
          $source_desc = "File entity ID: $id (" . $file->getFilename() . " - " . $file->getFileUri() . ")";
          $extracted_text = $this->pdfExtractor->extractFromFile($file);
        }
        else {
          $this->logger()->error("File entity ID $id not found.");
          return;
        }
      }
      else {
        // Try Media first.
        $media = $this->entityTypeManager->getStorage('media')->load($id);
        if ($media instanceof MediaInterface) {
          $source_desc = "Media entity ID: $id (" . $media->bundle() . " - " . $media->label() . ")";
          // Find document file field.
          $file = NULL;
          foreach (['field_media_document_file', 'field_media_document', 'field_document', 'field_media_file'] as $field_name) {
            if ($media->hasField($field_name) && !$media->get($field_name)->isEmpty()) {
              $file = $media->get($field_name)->entity;
              break;
            }
          }
          if ($file instanceof FileInterface) {
            $source_desc .= " -> File: " . $file->getFileUri();
            $extracted_text = $this->pdfExtractor->extractFromFile($file);
          }
          else {
            $this->logger()->error("No document file found on media ID $id.");
            return;
          }
        }
        else {
          // Try file if media not found.
          $file = $this->entityTypeManager->getStorage('file')->load($id);
          if ($file instanceof FileInterface) {
            $source_desc = "File entity ID: $id (" . $file->getFilename() . " - " . $file->getFileUri() . ")";
            $extracted_text = $this->pdfExtractor->extractFromFile($file);
          }
          else {
            $this->logger()->error("Entity ID $id not found as media or file.");
            return;
          }
        }
      }
    }
    else {
      // String path.
      $source_desc = "Path: $target";
      $extracted_text = $this->pdfExtractor->extractFromPath($target);
    }

    if ($raw_only) {
      $this->output()->writeln($extracted_text);
    }
    else {
      $this->io()->title('=== PDF Text Extraction Result ===');
      $this->io()->text("Source: $source_desc");
      $this->io()->text("Characters Extracted: " . mb_strlen($extracted_text));
      $this->io()->section('Extracted Text:');
      $this->output()->writeln($extracted_text);
      $this->io()->newLine();
      $this->io()->success('Text extraction completed successfully.');
    }
  }

}
