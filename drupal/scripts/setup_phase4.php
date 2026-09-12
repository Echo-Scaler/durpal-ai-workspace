<?php

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRelationshipType;
use Drupal\group\Entity\GroupRole;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;

echo "=== Phase 4 Setup: Private Document Media & Text Extraction Pipeline ===\n";

$entity_type_manager = \Drupal::entityTypeManager();

// ─── Step 1: Create Field Storage for Private Document File ──────────────────
echo "--- Step 1: Configuring Field Storage for Private Document ---\n";
$field_storage = FieldStorageConfig::loadByName('media', 'field_media_document_file');
if (!$field_storage) {
  $field_storage = FieldStorageConfig::create([
    'field_name' => 'field_media_document_file',
    'entity_type' => 'media',
    'type' => 'file',
    'cardinality' => 1,
    'translatable' => TRUE,
    'settings' => [
      'target_type' => 'file',
      'display_field' => FALSE,
      'display_default' => FALSE,
      'uri_scheme' => 'private',
    ],
  ]);
  $field_storage->save();
  echo "Created field storage: media.field_media_document_file (uri_scheme: private)\n";
}
else {
  echo "Field storage media.field_media_document_file already exists.\n";
}

// ─── Step 2: Create Media Bundle company_document ────────────────────────────
echo "--- Step 2: Creating Media Bundle: company_document ---\n";
$media_type_storage = $entity_type_manager->getStorage('media_type');
$media_type = $media_type_storage->load('company_document');
if (!$media_type) {
  $media_type = $media_type_storage->create([
    'id' => 'company_document',
    'label' => 'Company Document',
    'description' => 'Confidential company document uploaded to private workspace.',
    'source' => 'file',
    'queue_thumbnail_downloads' => FALSE,
    'new_revision' => TRUE,
    'source_configuration' => [
      'source_field' => 'field_media_document_file',
    ],
    'field_map' => [
      'name' => 'name',
    ],
  ]);
  $media_type->save();
  echo "Created media bundle: company_document\n";
}
else {
  echo "Media bundle company_document already exists.\n";
}

// ─── Step 3: Create Field Instance on company_document ───────────────────────
echo "--- Step 3: Configuring Field Instance on company_document ---\n";
$field_instance = FieldConfig::loadByName('media', 'company_document', 'field_media_document_file');
if (!$field_instance) {
  $field_instance = FieldConfig::create([
    'field_storage' => $field_storage,
    'bundle' => 'company_document',
    'label' => 'Document File',
    'description' => 'Upload private company documents (PDF or DOCX). Direct public URL access is blocked.',
    'required' => TRUE,
    'settings' => [
      'file_directory' => 'documents',
      'file_extensions' => 'pdf docx',
      'max_filesize' => '64MB',
      'description_field' => FALSE,
    ],
  ]);
  $field_instance->save();
  echo "Created field instance: media.company_document.field_media_document_file\n";
}
else {
  echo "Field instance media.company_document.field_media_document_file already exists.\n";
}

// ─── Step 4: Configure Form & View Display for company_document ──────────────
echo "--- Step 4: Configuring Form and View Displays ---\n";
$form_display = $entity_type_manager->getStorage('entity_form_display')->load('media.company_document.default');
if (!$form_display) {
  $form_display = $entity_type_manager->getStorage('entity_form_display')->create([
    'targetEntityType' => 'media',
    'bundle' => 'company_document',
    'mode' => 'default',
    'status' => TRUE,
  ]);
}
$form_display->setComponent('name', [
  'type' => 'string_textfield',
  'weight' => -5,
])->setComponent('field_media_document_file', [
  'type' => 'file_generic',
  'weight' => 0,
])->setComponent('status', [
  'type' => 'boolean_checkbox',
  'weight' => 10,
])->save();
echo "Saved entity form display for company_document.\n";

$view_display = $entity_type_manager->getStorage('entity_view_display')->load('media.company_document.default');
if (!$view_display) {
  $view_display = $entity_type_manager->getStorage('entity_view_display')->create([
    'targetEntityType' => 'media',
    'bundle' => 'company_document',
    'mode' => 'default',
    'status' => TRUE,
  ]);
}
$view_display->setComponent('field_media_document_file', [
  'type' => 'file_default',
  'label' => 'above',
  'weight' => 0,
])->save();
echo "Saved entity view display for company_document.\n";

// Clear group relation plugin cache so group_media:company_document is recognized.
\Drupal::service('group_relation_type.manager')->clearCachedDefinitions();

// ─── Step 5: Connect company_document to company_workspace Group Type ────────
echo "--- Step 5: Connecting Media Bundle to Group Type (group_media:company_document) ---\n";
/** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $rel_type_storage */
$rel_type_storage = $entity_type_manager->getStorage('group_relationship_type');
$relationship_type_id = $rel_type_storage->getRelationshipTypeId('company_workspace', 'group_media:company_document');
$rel_type = $rel_type_storage->load($relationship_type_id);
if (!$rel_type) {
  $rel_type = $rel_type_storage->create([
    'id' => $relationship_type_id,
    'group_type' => 'company_workspace',
    'content_plugin' => 'group_media:company_document',
    'plugin_config' => [
      'group_cardinality' => 0,
      'entity_cardinality' => 1,
      'use_creation_wizard' => FALSE,
    ],
  ]);
  $rel_type->save();
  echo "Created group relationship type: $relationship_type_id\n";
}
else {
  echo "Group relationship type $relationship_type_id already exists.\n";
}

// ─── Step 6: Configure Multi-Tenant Group Role Permissions for Media ─────────
echo "--- Step 6: Configuring Group Role Permissions for Media Documents ---\n";
$perm_provider = \Drupal::service('group_relation_type.manager')->getPermissionProvider('group_media:company_document');
$available_perms = array_keys($perm_provider->buildPermissions());
echo "Available permissions for group_media:company_document: " . implode(', ', $available_perms) . "\n";

$group_role_storage = $entity_type_manager->getStorage('group_role');

// 1. Anonymous: Strictly NO permissions (zero access to private company docs)
$anon_role = $group_role_storage->load('company_workspace-anonymous');
if ($anon_role) {
  $current_perms = $anon_role->getPermissions();
  foreach ($available_perms as $perm) {
    $current_perms = array_diff($current_perms, [$perm]);
  }
  $anon_role->set('permissions', array_values($current_perms));
  $anon_role->save();
  echo "Verified anonymous role has NO document permissions.\n";
}

// 2. Outsider: Strictly NO permissions (isolated from other companies)
$outsider_role = $group_role_storage->load('company_workspace-outsider');
if ($outsider_role) {
  $current_perms = $outsider_role->getPermissions();
  foreach ($available_perms as $perm) {
    $current_perms = array_diff($current_perms, [$perm]);
  }
  $outsider_role->set('permissions', array_values($current_perms));
  $outsider_role->save();
  echo "Verified outsider role has NO document permissions.\n";
}

// 3. Member: Can view documents in their own company workspace
$member_role = $group_role_storage->load('company_workspace-member');
if ($member_role) {
  $current_perms = $member_role->getPermissions();
  $member_desired = [
    'view group_media:company_document relationship',
    'view group_media:company_document entity',
  ];
  $new_perms = array_unique(array_merge($current_perms, array_intersect($member_desired, $available_perms)));
  $member_role->set('permissions', array_values($new_perms));
  $member_role->save();
  echo "Updated member role permissions.\n";
}

// 4. Company Member: Can view and add documents to their company workspace
$company_member_role = $group_role_storage->load('company_workspace-company_member');
if ($company_member_role) {
  $current_perms = $company_member_role->getPermissions();
  $cm_desired = [
    'view group_media:company_document relationship',
    'view group_media:company_document entity',
    'create group_media:company_document relationship',
    'create group_media:company_document entity',
  ];
  $new_perms = array_unique(array_merge($current_perms, array_intersect($cm_desired, $available_perms)));
  $company_member_role->set('permissions', array_values($new_perms));
  $company_member_role->save();
  echo "Updated company_member role permissions.\n";
}

// 5. Company Admin: Full control over company documents in their workspace
$admin_role = $group_role_storage->load('company_workspace-company_admin');
if ($admin_role) {
  $current_perms = $admin_role->getPermissions();
  $admin_desired = [
    'view group_media:company_document relationship',
    'view group_media:company_document entity',
    'create group_media:company_document relationship',
    'create group_media:company_document entity',
    'update any group_media:company_document relationship',
    'update own group_media:company_document relationship',
    'update any group_media:company_document entity',
    'update own group_media:company_document entity',
    'delete any group_media:company_document relationship',
    'delete own group_media:company_document relationship',
    'delete any group_media:company_document entity',
    'delete own group_media:company_document entity',
  ];
  $new_perms = array_unique(array_merge($current_perms, array_intersect($admin_desired, $available_perms)));
  $admin_role->set('permissions', array_values($new_perms));
  $admin_role->save();
  echo "Updated company_admin role permissions.\n";
}

// ─── Step 7: Create Sample PDF & Upload to Company A Workspace ───────────────
echo "--- Step 7: Uploading Sample PDF to Company A (Group ID 6) ---\n";

function generateSimplePdf(string $text): string {
  $content = "BT\n/F1 12 Tf\n50 750 Td\n";
  $lines = explode("\n", $text);
  $first = true;
  foreach ($lines as $line) {
    $escaped = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $line);
    if ($first) {
      $content .= "($escaped) Tj\n";
      $first = false;
    } else {
      $content .= "0 -16 Td\n($escaped) Tj\n";
    }
  }
  $content .= "ET";
  $content_len = strlen($content);

  $pdf = "%PDF-1.4\n";
  $offsets = [];
  $offsets[1] = strlen($pdf);
  $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
  $offsets[2] = strlen($pdf);
  $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
  $offsets[3] = strlen($pdf);
  $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
  $offsets[4] = strlen($pdf);
  $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
  $offsets[5] = strlen($pdf);
  $pdf .= "5 0 obj\n<< /Length $content_len >>\nstream\n$content\nendstream\nendobj\n";

  $xref_offset = strlen($pdf);
  $pdf .= "xref\n0 6\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i <= 5; $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
  }
  $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref_offset\n%%EOF\n";

  return $pdf;
}

$sample_text = "Company A Q3 Financial and Strategic Report\n"
  . "Financial Overview: Company A Annual Revenue reached $5.2 Million in Q3 2026.\n"
  . "Enterprise Security: Private multi-tenant workspace isolation active.\n"
  . "AI Strategy: Full integration of Drupal Enterprise RAG Pipeline with pgvector.";

$pdf_binary = generateSimplePdf($sample_text);

/** @var \Drupal\file\FileRepositoryInterface $file_repo */
$file_repo = \Drupal::service('file.repository');
$file_uri = 'private://documents/company_a_q3_report_2026.pdf';

// Save the private file managed entity.
$file = $file_repo->writeData($pdf_binary, $file_uri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
$file->setOwnerId(2); // user_a
$file->setPermanent();
$file->save();

echo "Saved private managed file: ID=" . $file->id() . ", URI=" . $file->getFileUri() . "\n";
echo "Real filesystem path: " . \Drupal::service('file_system')->realpath($file->getFileUri()) . "\n";

// Create or update company_document Media entity.
$media_storage = $entity_type_manager->getStorage('media');
$existing_media = $media_storage->loadByProperties(['name' => 'Company A Q3 Financial Report 2026']);
if (!empty($existing_media)) {
  $media = reset($existing_media);
  $media->set('field_media_document_file', ['target_id' => $file->id()]);
  $media->save();
  echo "Updated existing Media entity ID=" . $media->id() . "\n";
}
else {
  $media = $media_storage->create([
    'bundle' => 'company_document',
    'name' => 'Company A Q3 Financial Report 2026',
    'uid' => 2, // user_a
    'status' => 1,
    'field_media_document_file' => [
      'target_id' => $file->id(),
    ],
  ]);
  $media->save();
  echo "Created Media entity ID=" . $media->id() . "\n";
}

// Associate media with Company A (Group ID 6)
$group_storage = $entity_type_manager->getStorage('group');
$group_a = $group_storage->load(6);
if ($group_a instanceof Group) {
  // Check if relationship already exists
  $existing_rel = $entity_type_manager->getStorage('group_relationship')->loadByProperties([
    'gid' => 6,
    'plugin_id' => 'group_media:company_document',
    'entity_id' => $media->id(),
  ]);
  if (empty($existing_rel)) {
    $relationship = $group_a->addRelationship($media, 'group_media:company_document');
    echo "Successfully associated Media ID=" . $media->id() . " with Group 6 (Company A). Relationship ID=" . $relationship->id() . "\n";
  }
  else {
    echo "Media is already associated with Group 6 (Company A).\n";
  }
}
else {
  echo "ERROR: Group ID 6 (Company A) not found!\n";
}

echo "=== Phase 4 Setup Completed Successfully ===\n";
