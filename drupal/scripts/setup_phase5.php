<?php

use Drupal\key\Entity\Key;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Item\Field;

echo "=== Phase 5 Setup: Search API Vector Index & Embedding Configuration (pgvector) ===\n";

$entity_type_manager = \Drupal::entityTypeManager();
$config_factory = \Drupal::configFactory();

// ─── Step 1: Ensure Necessary Modules Are Enabled ─────────────────────────────
echo "\n--- Step 1: Checking Required Modules ---\n";
$module_installer = \Drupal::service('module_installer');
$required_modules = [
  'key',
  'search_api',
  'search_api_attachments',
  'ai',
  'ai_search',
  'ai_vdb_provider_postgres',
  'ai_provider_openai',
  'company_document_extractor',
];

foreach ($required_modules as $mod) {
  if (!\Drupal::moduleHandler()->moduleExists($mod)) {
    echo "Installing module: $mod...\n";
    $module_installer->install([$mod]);
  }
  else {
    echo "Module already enabled: $mod\n";
  }
}

// ─── Step 2: Configure Key Entities ──────────────────────────────────────────
echo "\n--- Step 2: Configuring Key Entities ---\n";
$key_storage = $entity_type_manager->getStorage('key');

// 1. PostgreSQL Database Password Key
$pg_key = $key_storage->load('postgres_password');
if (!$pg_key) {
  $pg_key = $key_storage->create([
    'id' => 'postgres_password',
    'label' => 'PostgreSQL Password',
    'key_type' => 'authentication',
    'key_provider' => 'config',
    'key_provider_settings' => [
      'key_value' => 'drupal_secret',
    ],
  ]);
  $pg_key->save();
  echo "Created Key: postgres_password\n";
}
else {
  echo "Key postgres_password already exists.\n";
}

// 2. OpenAI API Key
$openai_env_key = getenv('OPENAI_API_KEY') ?: 'dummy_openai_key';
$openai_key = $key_storage->load('openai_api_key');
if (!$openai_key) {
  $openai_key = $key_storage->create([
    'id' => 'openai_api_key',
    'label' => 'OpenAI API Key',
    'key_type' => 'authentication',
    'key_provider' => 'config',
    'key_provider_settings' => [
      'key_value' => $openai_env_key,
    ],
  ]);
  $openai_key->save();
  echo "Created Key: openai_api_key\n";
}
else {
  echo "Key openai_api_key already exists.\n";
}

// ─── Step 3: Configure Module Settings ───────────────────────────────────────
echo "\n--- Step 3: Configuring Provider Settings ---\n";

// Configure ai_vdb_provider_postgres
$vdb_config = $config_factory->getEditable('ai_vdb_provider_postgres.settings');
$vdb_config
  ->set('host', 'postgres')
  ->set('port', 5432)
  ->set('username', 'drupal')
  ->set('password', 'postgres_password')
  ->set('default_database', 'drupal')
  ->save();
echo "Configured ai_vdb_provider_postgres.settings (host=postgres, port=5432, db=drupal).\n";

// Configure ai_provider_openai
$openai_config = $config_factory->getEditable('ai_provider_openai.settings');
$openai_config
  ->set('api_key', 'openai_api_key')
  ->set('moderation', FALSE)
  ->save();
// Configure search_api_attachments extractor config
$saa_config = $config_factory->getEditable('search_api_attachments.admin_config');
$saa_config->set('pdfparser_extractor_configuration', [])->save();
echo "Configured search_api_attachments.admin_config (pdfparser_extractor_configuration).\n";

// ─── Step 4: Create / Update Search API Server ───────────────────────────────
echo "\n--- Step 4: Configuring Search API Server (pgvector) ---\n";
$server_storage = $entity_type_manager->getStorage('search_api_server');
$server = $server_storage->load('company_vector_server');

$server_backend_config = [
  'database' => 'postgres',
  'database_settings' => [
    'database_name' => 'drupal',
    'collection' => 'company_documents_index',
    'metric' => 'cosine_similarity',
    'vector_index_strategy' => 'none',
  ],
  'embeddings_engine' => 'openai__text-embedding-3-small',
  'chat_model' => 'openai__gpt-4o-mini',
  'embeddings_engine_configuration' => [
    'set_dimensions' => TRUE,
    'dimensions' => 1536,
  ],
  'embedding_strategy' => 'contextual_chunks',
  'embedding_strategy_configuration' => [
    'chunk_size' => '800',
    'chunk_min_overlap' => '150',
    'contextual_content_max_percentage' => '30',
    'skip_moderation' => TRUE,
  ],
  'embedding_strategy_details' => '',
  'include_raw_embedding_vector' => FALSE,
];

if (!$server) {
  $server = Server::create([
    'id' => 'company_vector_server',
    'name' => 'Company Vector Server (pgvector)',
    'description' => 'PostgreSQL pgvector backend with OpenAI text-embedding-3-small (1536 dims).',
    'backend' => 'search_api_ai_search',
    'backend_config' => $server_backend_config,
    'status' => TRUE,
  ]);
  $server->save();
  echo "Created Search API Server: company_vector_server\n";
}
else {
  $server->set('backend_config', $server_backend_config);
  $server->save();
  echo "Updated Search API Server: company_vector_server\n";
}

// ─── Step 5: Create / Update Search API Index ────────────────────────────────
echo "\n--- Step 5: Configuring Search API Index (company_documents_index) ---\n";
$index_storage = $entity_type_manager->getStorage('search_api_index');
$index = $index_storage->load('company_documents_index');

if (!$index) {
  $index = Index::create([
    'id' => 'company_documents_index',
    'name' => 'Company Documents Index',
    'description' => 'Vector index for company documents with 800 token chunking, 150 overlap, and group_id / company_id tenant filtering.',
    'server' => 'company_vector_server',
    'datasources' => ['entity:media'],
    'datasource_settings' => [
      'entity:media' => [
        'bundles' => [
          'default' => FALSE,
          'selected' => ['company_document'],
        ],
      ],
    ],
    'status' => TRUE,
  ]);
}
else {
  $index->setServer($server);
}

// Add Processors
// 1. File attachments processor (search_api_attachments)
$index->addProcessor(\Drupal::service('plugin.manager.search_api.processor')->createInstance('file_attachments', [
  '#index' => $index,
  'excluded_extensions' => 'aif art avi bmp gif ico mov mp3 mp4 mpeg mpg oga ogv png psd ra ram rgb tif tiff wav webm wmv',
  'number_indexed' => 0,
  'max_filesize' => '0',
  'read_text_files_directly' => FALSE,
]));

// 2. Company Document Group processor (tenant isolation)
$index->addProcessor(\Drupal::service('plugin.manager.search_api.processor')->createInstance('company_document_group', [
  '#index' => $index,
]));

// Define Fields
$fields_to_add = [
  'saa_field_media_document_file' => [
    'label' => 'Extracted Content',
    'property_path' => 'saa_field_media_document_file',
    'type' => 'text',
    'datasource_id' => NULL,
  ],
  'company_id' => [
    'label' => 'Company ID',
    'property_path' => 'company_id',
    'type' => 'integer',
    'datasource_id' => NULL,
  ],
  'group_id' => [
    'label' => 'Group ID',
    'property_path' => 'group_id',
    'type' => 'integer',
    'datasource_id' => NULL,
  ],
  'name' => [
    'label' => 'File Name',
    'property_path' => 'name',
    'type' => 'string',
    'datasource_id' => 'entity:media',
  ],
];

foreach ($fields_to_add as $field_id => $data) {
  if (!$index->getField($field_id)) {
    $f = new Field($index, $field_id);
    $f->setLabel($data['label']);
    $f->setPropertyPath($data['property_path']);
    $f->setType($data['type']);
    if (!empty($data['datasource_id'])) {
      $f->setDatasourceId($data['datasource_id']);
    }
    $index->addField($f);
    echo "Added field: $field_id\n";
  }
}

$index->save();
echo "Saved Search API Index: company_documents_index\n";

// ─── Step 6: Configure AI Search Indexing Options ────────────────────────────
echo "\n--- Step 6: Configuring AI Search Indexing Options ---\n";
$ai_search_index_config = $config_factory->getEditable('ai_search.index.company_documents_index');
$ai_search_index_config
  ->set('index_id', 'company_documents_index')
  ->set('control_field_max_length', FALSE)
  ->set('exclude_chunk_from_metadata', FALSE)
  ->set('indexing_options', [
    'saa_field_media_document_file' => [
      'indexing_option' => 'main_content',
    ],
    'company_id' => [
      'indexing_option' => 'attributes',
    ],
    'group_id' => [
      'indexing_option' => 'attributes',
    ],
    'name' => [
      'indexing_option' => 'contextual_content',
    ],
  ])
  ->save();
echo "Configured ai_search.index.company_documents_index (saa_field_media_document_file -> main_content, company_id/group_id -> attributes, name -> contextual_content).\n";

// ─── Step 7: Index Documents into pgvector ───────────────────────────────────
echo "\n--- Step 7: Indexing Documents into pgvector ---\n";
// Clear any cached tracker items and track media entities
$index->reindex();
$tracker = $index->getTrackerInstance();
$remaining = $tracker->getRemainingItemsCount();
$total = $tracker->getTotalItemsCount();
echo "Items to index: $remaining / $total\n";

$indexed = $index->indexItems(-1);
echo "Successfully indexed $indexed items!\n";

$remaining_after = $tracker->getRemainingItemsCount();
$total_after = $tracker->getTotalItemsCount();
echo "Remaining items: $remaining_after / $total_after\n";

echo "\n=== Phase 5 Setup Completed Successfully ===\n";
