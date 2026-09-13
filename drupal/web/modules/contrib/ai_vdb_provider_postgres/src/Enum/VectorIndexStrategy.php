<?php

namespace Drupal\ai_vdb_provider_postgres\Enum;

/**
 * Enum for pgvector index strategy.
 */
enum VectorIndexStrategy: string {
  case None = 'none';
  case Hnsw = 'hnsw';
  case IvfFlat = 'ivfflat';

  /**
   * Get the label for the strategy.
   *
   * @return string
   *   The human-readable label.
   */
  public function label(): string {
    return match ($this) {
      self::None => 'None (exact search, small datasets)',
      self::Hnsw => 'HNSW (recommended for large datasets)',
      self::IvfFlat => 'IVFFlat',
    };
  }

  /**
   * Get the options for a form select.
   *
   * @return array
   *   An array of labels keyed by value.
   */
  public static function options(): array {
    $options = [];
    foreach (self::cases() as $case) {
      $options[$case->value] = $case->label();
    }
    return $options;
  }

}
