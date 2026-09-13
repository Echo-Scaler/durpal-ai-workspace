<?php

namespace Drupal\ai_vdb_provider_postgres;

use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\ai_vdb_provider_postgres\Enum\VectorIndexStrategy;
use Drupal\ai_vdb_provider_postgres\Exception\AddFieldIfNotExistsException;
use Drupal\ai_vdb_provider_postgres\Exception\CreateCollectionException;
use Drupal\ai_vdb_provider_postgres\Exception\DatabaseConnectionException;
use Drupal\ai_vdb_provider_postgres\Exception\DeleteFromCollectionException;
use Drupal\ai_vdb_provider_postgres\Exception\DropCollectionException;
use Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException;
use Drupal\ai_vdb_provider_postgres\Exception\GetCollectionsException;
use Drupal\ai_vdb_provider_postgres\Exception\InsertIntoCollectionException;
use Drupal\ai_vdb_provider_postgres\Exception\QuerySearchException;
use Drupal\ai_vdb_provider_postgres\Exception\VectorIndexException;
use Drupal\ai_vdb_provider_postgres\Exception\VectorSearchException;

/**
 * Provides abstracted Postgres client to interface with pgvector.
 */
class PostgresPgvectorClient {

  protected const DATA_TYPE_MAPPING = [
    'integer' => 'INTEGER',
    'text' => 'TEXT',
    // Use BIGINT instead of TIMESTAMP because at index time, the provider
    // does not know whether the field value is a date or number.
    'date' => 'BIGINT',
    'decimal' => 'DECIMAL',
    'string' => 'VARCHAR',
    'boolean' => 'BOOLEAN',
  ];

  /**
   * Get the Postgres database connection.
   *
   * @return \PDO|false
   *   A connection to the Postgres database.
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\DatabaseConnectionException
   */
  public function getConnection(
    string $host,
    int $port,
    string $username,
    string $password,
    string $default_database,
    ?string $database = NULL,
  ): \PDO|FALSE {
    if (!isset($database)) {
      $database = $default_database;
    }
    $dsn = "pgsql:host={$host};dbname={$database};port={$port}";
    try {
      return new \PDO($dsn, $username, $password, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      ]);
    }
    catch (\PDOException $e) {
      throw new DatabaseConnectionException(
        message: 'Cannot connect to Postgres database using provided connection details: ' . $e->getMessage(),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function ping(\PDO $connection): bool {
    try {
      return (bool) $connection->query('SELECT 1')->fetchColumn();
    }
    catch (\PDOException) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\GetCollectionsException
   */
  public function getCollections(\PDO $connection): array {
    try {
      $statement = $connection->prepare('SELECT * FROM pg_catalog.pg_tables WHERE schemaname != ? AND schemaname != ?;');
      $statement->execute(['pg_catalog', 'information_schema']);
      $rows = $statement->fetchAll();
    }
    catch (\PDOException $e) {
      throw new GetCollectionsException(message: $e->getMessage());
    }

    $tables = array_map(
      callback: function ($row) {
        return $row['tablename'];
      },
      array: $rows
    );
    return $tables;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\CreateCollectionException
   */
  public function createCollection(
    string $collection_name,
    int $dimension,
    \PDO $connection,
  ): void {
    $escaped_collection_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $collection_name,
      connection: $connection,
    );
    try {
      $connection->exec("CREATE TABLE {$escaped_collection_name} (id bigserial PRIMARY KEY, content VARCHAR, drupal_entity_id VARCHAR, drupal_long_id VARCHAR, server_id VARCHAR, index_id VARCHAR, embedding vector({$dimension}));");
    }
    catch (\PDOException $e) {
      throw new CreateCollectionException(message: $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\DropCollectionException
   */
  public function dropCollection(
    string $collection_name,
    \PDO $connection,
  ): void {
    $escaped_collection_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $collection_name,
      connection: $connection,
    );
    try {
      $connection->exec("DROP TABLE IF EXISTS {$escaped_collection_name} CASCADE;");
    }
    catch (\PDOException $e) {
      throw new DropCollectionException(message: $e->getMessage());
    }
  }

  /**
   * Get the vector index strategy and metric for a collection.
   *
   * @param string $collection_name
   *   The collection name.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @return array
   *   An array containing 'strategy' and 'metric'.
   */
  public function getVectorIndexDetails(string $collection_name, \PDO $connection): array {
    try {
      $statement = $connection->prepare('SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexdef LIKE ?;');
      $statement->execute([$collection_name, '%embedding%']);
      $rows = $statement->fetchAll();
    }
    catch (\PDOException) {
      return [
        'strategy' => VectorIndexStrategy::None,
        'metric' => VdbSimilarityMetrics::CosineSimilarity,
      ];
    }
    if (!$rows) {
      return [
        'strategy' => VectorIndexStrategy::None,
        'metric' => VdbSimilarityMetrics::CosineSimilarity,
      ];
    }
    foreach ($rows as $row) {
      $indexdef = $row['indexdef'];
      $strategy = VectorIndexStrategy::None;
      if (str_contains($indexdef, ' USING hnsw (')) {
        $strategy = VectorIndexStrategy::Hnsw;
      }
      elseif (str_contains($indexdef, ' USING ivfflat (')) {
        $strategy = VectorIndexStrategy::IvfFlat;
      }

      if ($strategy !== VectorIndexStrategy::None) {
        $metric = VdbSimilarityMetrics::CosineSimilarity;
        if (str_contains($indexdef, 'vector_l2_ops')) {
          $metric = VdbSimilarityMetrics::EuclideanDistance;
        }
        elseif (str_contains($indexdef, 'vector_ip_ops')) {
          $metric = VdbSimilarityMetrics::InnerProduct;
        }
        return [
          'strategy' => $strategy,
          'metric' => $metric,
        ];
      }
    }
    return [
      'strategy' => VectorIndexStrategy::None,
      'metric' => VdbSimilarityMetrics::CosineSimilarity,
    ];
  }

  /**
   * Get the vector index strategy for a collection.
   *
   * @param string $collection_name
   *   The collection name.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @return \Drupal\ai_vdb_provider_postgres\Enum\VectorIndexStrategy
   *   The vector index strategy.
   */
  public function getVectorIndexStrategy(string $collection_name, \PDO $connection): VectorIndexStrategy {
    return $this->getVectorIndexDetails($collection_name, $connection)['strategy'];
  }

  /**
   * Create a vector index for a collection.
   *
   * @param string $collection_name
   *   The collection name.
   * @param \Drupal\ai_vdb_provider_postgres\Enum\VectorIndexStrategy $strategy
   *   The vector index strategy.
   * @param \PDO $connection
   *   The Postgres connection.
   * @param \Drupal\ai\Enum\VdbSimilarityMetrics $metric
   *   The similarity metric.
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\VectorIndexException
   */
  public function createVectorIndex(
    string $collection_name,
    VectorIndexStrategy $strategy,
    \PDO $connection,
    VdbSimilarityMetrics $metric = VdbSimilarityMetrics::CosineSimilarity,
  ): void {
    if ($strategy === VectorIndexStrategy::None) {
      return;
    }

    $index_name = "{$collection_name}_embedding_idx";
    $escaped_index_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $index_name,
      connection: $connection,
    );
    $escaped_collection_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $collection_name,
      connection: $connection,
    );

    $strategy_sql = match ($strategy) {
      VectorIndexStrategy::Hnsw => 'hnsw',
      VectorIndexStrategy::IvfFlat => 'ivfflat',
      default => throw new VectorIndexException('Unsupported index strategy.'),
    };

    $opclass = match ($metric) {
      VdbSimilarityMetrics::CosineSimilarity => 'vector_cosine_ops',
      VdbSimilarityMetrics::EuclideanDistance => 'vector_l2_ops',
      VdbSimilarityMetrics::InnerProduct => 'vector_ip_ops',
    };

    $query = "CREATE INDEX {$escaped_index_name} ON {$escaped_collection_name} USING {$strategy_sql} (embedding {$opclass});";
    try {
      $connection->exec($query);
    }
    catch (\PDOException $e) {
      throw new VectorIndexException(message: $e->getMessage());
    }
  }

  /**
   * Drop the vector index for a collection.
   *
   * @param string $collection_name
   *   The collection name.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\VectorIndexException
   */
  public function dropVectorIndex(string $collection_name, \PDO $connection): void {
    $index_name = "{$collection_name}_embedding_idx";
    $escaped_index_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $index_name,
      connection: $connection,
    );
    $query = "DROP INDEX IF EXISTS {$escaped_index_name};";
    try {
      $connection->exec($query);
    }
    catch (\PDOException $e) {
      throw new VectorIndexException(message: $e->getMessage());
    }
  }

  /**
   * Ensure the vector index for a collection matches the requested strategy.
   *
   * @param string $collection_name
   *   The collection name.
   * @param \Drupal\ai_vdb_provider_postgres\Enum\VectorIndexStrategy $strategy
   *   The vector index strategy.
   * @param \PDO $connection
   *   The Postgres connection.
   * @param \Drupal\ai\Enum\VdbSimilarityMetrics $metric
   *   The similarity metric.
   *
   * @return bool
   *   True if the index was changed, false otherwise.
   */
  public function ensureVectorIndex(
    string $collection_name,
    VectorIndexStrategy $strategy,
    \PDO $connection,
    VdbSimilarityMetrics $metric = VdbSimilarityMetrics::CosineSimilarity,
  ): bool {
    $current_details = $this->getVectorIndexDetails($collection_name, $connection);
    if ($current_details['strategy'] === $strategy && $current_details['metric'] === $metric) {
      return FALSE;
    }

    if ($strategy === VectorIndexStrategy::None) {
      $this->dropVectorIndex($collection_name, $connection);
      return TRUE;
    }

    // Drop current index if it exists (regardless of type).
    $this->dropVectorIndex($collection_name, $connection);
    // Create new index.
    $this->createVectorIndex($collection_name, $strategy, $connection, $metric);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\InsertIntoCollectionException
   */
  public function insertIntoCollection(
    string $collection_name,
    array $drupal_entity_id,
    array $drupal_long_id,
    array $content,
    array $vector,
    array $server_id,
    array $index_id,
    array $extra_fields,
    \PDO $connection,
  ): void {
    $vector_string = $this->prepareVectorArrayForSql(
      vector: $vector['value'],
      connection: $connection,
    );
    $escaped_collection_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $collection_name,
      connection: $connection,
    );
    // Prepare columns and values for extra fields.
    $extra_fields_columns = '';
    $extra_fields_values = '';
    $extra_fields_params = [];

    $relation_queries = [];

    foreach ($extra_fields as $field_name => $field_data) {
      if ($field_data['is_multiple']) {
        if ($relation_query = $this->prepareRelationQuery($collection_name, $field_name, $field_data, $connection)) {
          $relation_queries[] = $relation_query;
        }
      }
      else {
        $extra_fields_columns .= ', ' . $this->escapeIdentifierForSql($field_name, $connection);
        $extra_fields_values .= ', ?';
        $extra_fields_params[] = $field_data['value'];
      }
    }
    $main_query = "INSERT INTO {$escaped_collection_name} (content, drupal_entity_id, drupal_long_id, server_id, index_id, embedding{$extra_fields_columns}) VALUES (?, ?, ?, ?, ?, ?::vector{$extra_fields_values});";

    $params = array_merge([
      $content['value'],
      $drupal_entity_id['value'],
      $drupal_long_id['value'],
      $server_id['value'],
      $index_id['value'],
      $vector_string,
    ], $extra_fields_params);

    try {
      $statement = $connection->prepare($main_query);
      $statement->execute($params);
    }
    catch (\PDOException $e) {
      throw new InsertIntoCollectionException(message: $e->getMessage());
    }
    foreach ($relation_queries as $relation_query) {
      try {
        $statement = $connection->prepare($relation_query['query']);
        $statement->execute($relation_query['params']);
      }
      catch (\PDOException $e) {
        throw new InsertIntoCollectionException(message: $e->getMessage());
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\DeleteFromCollectionException
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   */
  public function deleteFromCollection(
    string $collection_name,
    array $ids,
    \PDO $connection,
  ): void {
    if (empty($ids)) {
      return;
    }
    $escaped_collection_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $collection_name,
      connection: $connection,
    );
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    try {
      $statement = $connection->prepare("DELETE FROM {$escaped_collection_name} WHERE drupal_entity_id IN ({$placeholders});");
      $statement->execute(array_values($ids));
    }
    catch (\PDOException $e) {
      throw new DeleteFromCollectionException(message: $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\QuerySearchException
   */
  public function querySearch(
    string $collection_name,
    array $output_fields,
    string $filters,
    int $limit,
    int $offset,
    \PDO $connection,
  ): array {
    $escaped_collection_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $collection_name,
      connection: $connection,
    );
    $prepared_output_fields = $this->prepareFieldArrayForSql(fields: $output_fields, connection: $connection, collection_name: $collection_name);
    if (empty($filters)) {
      $query = "SELECT {$prepared_output_fields} FROM {$escaped_collection_name} LIMIT " . max(0, $limit) . " OFFSET " . max(0, $offset) . ";";
    }
    else {
      $query = "SELECT {$prepared_output_fields} FROM {$escaped_collection_name} {$filters} LIMIT " . max(0, $limit) . " OFFSET " . max(0, $offset) . ";";
    }
    try {
      return $connection->query($query)->fetchAll();
    }
    catch (\PDOException $e) {
      throw new QuerySearchException(message: $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\VectorSearchException
   */
  public function vectorSearch(
    string $collection_name,
    array $vector_input,
    array $output_fields,
    string $filters,
    int $limit,
    int $offset,
    VdbSimilarityMetrics $metric_type,
    \PDO $connection,
  ): array {
    $metric_name = match ($metric_type) {
      VdbSimilarityMetrics::EuclideanDistance => '<->',
      VdbSimilarityMetrics::CosineSimilarity => '<=>',
      VdbSimilarityMetrics::InnerProduct => '<#>',
    };
    $escaped_collection_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $collection_name,
      connection: $connection,
    );
    $prepared_output_fields = $this->prepareFieldArrayForSql(fields: $output_fields, connection: $connection, collection_name: $collection_name);
    $vectors = $this->prepareVectorArrayForSql(vector: $vector_input, connection: $connection);
    // Escape the output fields.
    $escaped_outfield_fields = array_map(
      callback: function ($field) use ($connection) {
        return $this->escapeIdentifierForSql(identifier_to_escape: $field, connection: $connection);
      },
      array: $output_fields
    );
    $outfield_fields = implode(',', $escaped_outfield_fields);
    $alias = 'subquery';
    $limit = max(0, $limit);
    $offset = max(0, $offset);
    if (empty($filters)) {
      // CosineSimilarity returns a similarity (1 - distance) for API
      // compatibility, but orders by the raw inner distance ascending so the
      // pgvector ANN index (HNSW/IVFFlat) can serve the query; ordering by the
      // outer "(1 - distance) DESC" would force a sequential scan.
      if ($metric_type === VdbSimilarityMetrics::CosineSimilarity) {
        $query = "SELECT (1-{$alias}.real_distance) as distance, {$outfield_fields} FROM (SELECT embedding {$metric_name} ?::vector as real_distance, {$prepared_output_fields} FROM {$escaped_collection_name}) as {$alias} ORDER BY {$alias}.real_distance ASC LIMIT {$limit} OFFSET {$offset};";
      }
      else {
        $query = "SELECT embedding {$metric_name} ?::vector as distance, {$prepared_output_fields} FROM {$escaped_collection_name} ORDER BY distance LIMIT {$limit} OFFSET {$offset};";
      }
    }
    else {
      // See the unfiltered branch above: order by the raw inner distance so the
      // pgvector ANN index can serve the query.
      if ($metric_type === VdbSimilarityMetrics::CosineSimilarity) {
        $query = "SELECT (1-{$alias}.real_distance) as distance, {$outfield_fields} FROM (SELECT embedding {$metric_name} ?::vector as real_distance, {$prepared_output_fields} FROM {$escaped_collection_name} {$filters}) as {$alias} ORDER BY {$alias}.real_distance ASC LIMIT {$limit} OFFSET {$offset};";
      }
      else {
        $query = "SELECT embedding {$metric_name} ?::vector as distance, {$prepared_output_fields} FROM {$escaped_collection_name} {$filters} ORDER BY distance LIMIT {$limit} OFFSET {$offset};";
      }
    }
    try {
      $statement = $connection->prepare($query);
      $statement->execute([$vectors]);
      return $statement->fetchAll();
    }
    catch (\PDOException $e) {
      throw new VectorSearchException(message: $e->getMessage());
    }
  }

  /**
   * Transform an array of field identifier strings for use in a SQL statement.
   *
   * @param array $fields
   *   Field array.
   * @param \PDO $connection
   *   The Postgres connection.
   * @param string|null $collection_name
   *   The collection name.
   *
   * @return string
   *   Array formatted as a field string.
   *   Eg: 'id,drupal_entity_id,drupal_long_id'
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   */
  public function prepareFieldArrayForSql(array $fields, \PDO $connection, $collection_name = NULL): string {
    if (empty($fields)) {
      return '';
    }
    $array_formatted_as_string = '';
    $last_element = end(array: $fields);
    foreach ($fields as $field) {
      if ($collection_name) {
        $array_formatted_as_string .= $this->escapeIdentifierForSql(identifier_to_escape: $collection_name, connection: $connection) . '.';
      }
      if ($field === $last_element) {
        $array_formatted_as_string .=
          $this->escapeIdentifierForSql(identifier_to_escape: $field, connection: $connection) . '';
        break;
      }
      $array_formatted_as_string .=
        $this->escapeIdentifierForSql(identifier_to_escape: $field, connection: $connection) . ',';
    }
    return $array_formatted_as_string;
  }

  /**
   * Transform an array of vectors to string for use in a SQL statement.
   *
   * @param array $vector
   *   Vector array.
   *   Normally an array of floats.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @return string
   *   Array formatted as a string.
   *   Eg: '[1.22424,-2.12312,-1.34654]'
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   */
  public function prepareVectorArrayForSql(array $vector, \PDO $connection): string {
    return '[' . implode(separator: ',', array: $vector) . ']';
  }

  /**
   * Transform an array of strings to string for use in a SQL statement.
   *
   * @param array $items
   *   An array of string items.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @return string
   *   Array of strings formatted as a string for SQL.
   *   Eg: "('first item', 'second item', 'third item')"
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   */
  public function prepareStringArrayForSql(array $items, \PDO $connection): string {
    $escaped_strings = [];
    foreach ($items as $item) {
      $escaped_strings[] = $this->escapeStringForSql(string_to_escape: $item, connection: $connection);
    }
    return '(' . implode(separator: ',', array: $escaped_strings) . ')';
  }

  /**
   * Escape a string for use in a Postgres SQL statement.
   *
   * @param string $string_to_escape
   *   The string to escape.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @return string
   *   A string containing the escaped data.
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   */
  private function escapeStringForSql(string $string_to_escape, \PDO $connection): string {
    $result = $connection->quote($string_to_escape);
    if ($result === FALSE) {
      throw new EscapeStringException(message: 'Could not quote string literal.');
    }
    return $result;
  }

  /**
   * Escape a string identifier for use in a postgres SQL statement.
   *
   * @param string $identifier_to_escape
   *   The string identifier to escape.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @return string
   *   A string containing the escaped data.
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   */
  public function escapeIdentifierForSql(string $identifier_to_escape, \PDO $connection): string {
    return '"' . str_replace('"', '""', $identifier_to_escape) . '"';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\AddFieldIfNotExistsException
   */
  public function updateFields($fields, string $collection_name, \PDO $connection): void {
    foreach ($fields as $field) {
      $field_data_definition = $field->getDataDefinition();

      // Make assumption of basic data type if we can't get more info.
      if (!method_exists($field_data_definition, 'getFieldDefinition')) {
        $this->addFieldIfNotExists(FALSE, 'string', $field->getFieldIdentifier(), $collection_name, $connection);
        continue;
      }
      $isMultiple = TRUE;

      $field_definition = $field_data_definition->getFieldDefinition();
      if ($field_definition instanceof BaseFieldDefinition) {
        $field_cardinality = $field_definition->getCardinality();
      }
      else {
        $field_cardinality =
          $field_definition->get('fieldStorage')->getCardinality();
      }
      if ($field_cardinality === 1) {
        $isMultiple = FALSE;
      }
      $this->addFieldIfNotExists($isMultiple, $field->getType(), $field->getFieldIdentifier(), $collection_name, $connection);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\EscapeStringException
   * @throws \Drupal\ai_vdb_provider_postgres\Exception\AddFieldIfNotExistsException
   */
  protected function addFieldIfNotExists(bool $isMultiple, string $data_type, string $name, string $collection_name, \PDO $connection): void {
    $escaped_collection_name = $this->escapeIdentifierForSql(
      identifier_to_escape: $collection_name,
      connection: $connection,
    );
    $postgres_type = self::DATA_TYPE_MAPPING[$data_type];
    $escaped_field_name = $this->escapeIdentifierForSql($name, $connection);

    // If isMultiple is true, create a new relationship table.
    if ($isMultiple) {
      $relation_table = $this->getRelationTableName($collection_name, $name, $connection);
      $create_relation_table = "CREATE TABLE IF NOT EXISTS {$relation_table} (id SERIAL PRIMARY KEY, value {$postgres_type} NOT NULL, chunk_id INT NOT NULL, FOREIGN KEY(chunk_id) REFERENCES {$escaped_collection_name}(id) ON DELETE CASCADE);";
      try {
        $connection->exec($create_relation_table);
      }
      catch (\PDOException $e) {
        throw new AddFieldIfNotExistsException(message: $e->getMessage());
      }
    }
    else {
      $query = "ALTER TABLE {$escaped_collection_name} ADD COLUMN IF NOT EXISTS {$escaped_field_name} {$postgres_type};";
      try {
        $connection->exec($query);
      }
      catch (\PDOException $e) {
        throw new AddFieldIfNotExistsException(message: $e->getMessage());
      }
    }
  }

  /**
   * Prepare a query to insert multiple field values into a relation table.
   *
   * @param string $collection_name
   *   The collection name.
   * @param string $field_name
   *   The field name.
   * @param array $field_data
   *   The field data.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @return array|null
   *   The SQL query and parameters, or null when there is nothing to insert.
   */
  protected function prepareRelationQuery($collection_name, $field_name, $field_data, \PDO $connection): ?array {
    $query = '';
    $params = [];
    // Prepare entries for relation table.
    $relation_table_fields = [];
    $escaped_relation_table_name = $this->getRelationTableName($collection_name, $field_name, $connection);
    if (!is_array($field_data['value'])) {
      $field_data['value'] = [$field_data['value']];
    }
    foreach ($field_data['value'] as $value) {
      if (empty($value)) {
        continue;
      }
      $relation_table_fields[$escaped_relation_table_name][] = $value;
    }

    foreach ($relation_table_fields as $escaped_relation_table_name => $field_values) {
      $query .= "INSERT INTO {$escaped_relation_table_name} (value, chunk_id) values ";
      $last_value = end($field_values);
      foreach ($field_values as $field_value) {
        $query .= "(?, currval(pg_get_serial_sequence(?, 'id')))";
        $params[] = $field_value;
        $params[] = $collection_name;
        if ($field_value === $last_value) {
          $query .= ';';
        }
        else {
          $query .= ",";
        }
      }
    }
    if (!$query) {
      return NULL;
    }
    return [
      'query' => $query,
      'params' => $params,
    ];
  }

  /**
   * Get the name of the relation table for a multiple value field.
   *
   * @param string $collection_name
   *   The collection name.
   * @param string $field_name
   *   The field name.
   * @param \PDO $connection
   *   The Postgres connection.
   *
   * @return string
   *   The escaped relation table name.
   */
  public function getRelationTableName($collection_name, $field_name, \PDO $connection): string {
    return $this->escapeIdentifierForSql(
      identifier_to_escape: "{$collection_name}__{$field_name}",
      connection: $connection,
    );
  }

}
