<?php

namespace Drupal\company_document_extractor\Plugin\search_api_attachments;

use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\search_api_attachments\TextExtractorPluginBase;
use Smalot\PdfParser\Parser;

/**
 * Provides PHP-native smalot/pdfparser extractor.
 *
 * @SearchApiAttachmentsTextExtractor(
 *   id = "pdfparser_extractor",
 *   label = @Translation("Smalot PDF Parser"),
 *   description = @Translation("PHP-native PDF text extractor using smalot/pdfparser."),
 * )
 */
class PdfParserExtractor extends TextExtractorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function extract(File $file) {
    if (!in_array($file->getMimeType(), $this->getPdfMimeTypes())) {
      return '';
    }

    $uri = $file->getFileUri();
    $filepath = $this->getRealpath($uri);

    if (!$filepath || !file_exists($filepath) || !is_readable($filepath)) {
      return '';
    }

    try {
      $parser = new Parser();
      $pdf = $parser->parseFile($filepath);
      $text = $pdf->getText();

      // Normalize extracted text.
      $text = str_replace(["\r\n", "\r"], "\n", $text);
      $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
      $text = preg_replace('/[ \t]+/u', ' ', $text);
      $text = preg_replace('/\n{3,}/u', "\n\n", $text);

      return trim($text);
    }
    catch (\Throwable $e) {
      \Drupal::logger('company_document_extractor')->error('PdfParserExtractor error for file @uri: @msg', [
        '@uri' => $uri,
        '@msg' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['info'] = [
      '#markup' => $this->t('PHP-native parser using <code>smalot/pdfparser</code>. No external binaries or Tika server needed.'),
    ];
    return $form;
  }

}
