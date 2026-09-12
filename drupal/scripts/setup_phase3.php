<?php

use Drupal\group\Entity\GroupType;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\Group;
use Drupal\group\PermissionScopeInterface;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;

$entity_type_manager = \Drupal::entityTypeManager();
$group_type_storage = $entity_type_manager->getStorage('group_type');
$group_role_storage = $entity_type_manager->getStorage('group_role');
$user_storage = $entity_type_manager->getStorage('user');
$group_storage = $entity_type_manager->getStorage('group');

echo "--- Step 1: Creating/Configuring Group Type: company_workspace ---\n";
$group_type_id = 'company_workspace';
$group_type = $group_type_storage->load($group_type_id);
if (!$group_type) {
  $group_type = $group_type_storage->create([
    'id' => $group_type_id,
    'label' => 'Company Workspace',
    'description' => 'Multi-tenancy workspace for company isolation',
    'creator_membership' => TRUE,
    'creator_wizard' => FALSE,
    'creator_roles' => [$group_type_id . '-company_admin'],
  ]);
  $group_type_storage->save($group_type);
  echo "Created group type: company_workspace\n";
}
else {
  $group_type->set('creator_roles', [$group_type_id . '-company_admin']);
  $group_type_storage->save($group_type);
  echo "Updated group type: company_workspace\n";
}

echo "--- Step 2: Configuring Group Roles & Permissions Isolation ---\n";

// Helper to create or update group roles
$configure_role = function($id, $label, $scope, $global_role = NULL, $admin = FALSE, $permissions = [], $weight = 0) use ($group_role_storage, $group_type_id) {
  $role = $group_role_storage->load($id);
  if (!$role) {
    $values = [
      'id' => $id,
      'label' => $label,
      'weight' => $weight,
      'scope' => $scope,
      'group_type' => $group_type_id,
      'admin' => $admin,
      'permissions' => $permissions,
    ];
    if ($global_role) {
      $values['global_role'] = $global_role;
    }
    $role = $group_role_storage->create($values);
  }
  else {
    $role->set('label', $label);
    $role->set('scope', $scope);
    $role->set('admin', $admin);
    $role->set('permissions', $permissions);
    $role->set('weight', $weight);
    if ($global_role) {
      $role->set('global_role', $global_role);
    }
  }
  $group_role_storage->save($role);
  echo "Configured role: $id (scope: $scope, admin: " . ($admin ? 'true' : 'false') . ")\n";
  return $role;
};

// 1. Anonymous (Outsider with anonymous global role) - ZERO permissions for multi-tenant security
$configure_role(
  "$group_type_id-anonymous",
  'Anonymous',
  PermissionScopeInterface::OUTSIDER_ID,
  RoleInterface::ANONYMOUS_ID,
  FALSE,
  [],
  -102
);

// 2. Outsider (Authenticated users outside the group) - ZERO permissions (Access Denied for other companies)
$configure_role(
  "$group_type_id-outsider",
  'Outsider',
  PermissionScopeInterface::OUTSIDER_ID,
  RoleInterface::AUTHENTICATED_ID,
  FALSE,
  [],
  -101
);

// 3. Insider Member (Basic member permissions for any group member)
$configure_role(
  "$group_type_id-member",
  'Member',
  PermissionScopeInterface::INSIDER_ID,
  RoleInterface::AUTHENTICATED_ID,
  FALSE,
  ['view group', 'view group_membership relationship', 'leave group'],
  -100
);

// 4. Custom Individual Role: company_admin
// Has full administration rights within the group (member invite, document create/delete)
$configure_role(
  "$group_type_id-company_admin",
  'Company Admin',
  PermissionScopeInterface::INDIVIDUAL_ID,
  NULL,
  TRUE, // Admin role has all permissions in this group
  [
    'view group',
    'edit group',
    'delete group',
    'administer members',
    'view group_membership relationship',
    'update own group_membership relationship',
    'leave group',
    'access content overview',
  ],
  10
);

// 5. Custom Individual Role: company_member
// Can view documents, chat with AI, view workspace
$configure_role(
  "$group_type_id-company_member",
  'Company Member',
  PermissionScopeInterface::INDIVIDUAL_ID,
  NULL,
  FALSE,
  [
    'view group',
    'view group_membership relationship',
    'update own group_membership relationship',
    'leave group',
    'access content overview',
  ],
  0
);

echo "--- Step 3: Creating Test Users (User A and User B) ---\n";

$create_user = function($name, $mail, $pass) use ($user_storage) {
  $users = $user_storage->loadByProperties(['name' => $name]);
  if ($users) {
    $user = reset($users);
    echo "Found existing user: $name (uid: {$user->id()})\n";
    return $user;
  }
  $user = $user_storage->create([
    'name' => $name,
    'mail' => $mail,
    'pass' => $pass,
    'status' => 1,
  ]);
  $user_storage->save($user);
  echo "Created user: $name (uid: {$user->id()})\n";
  return $user;
};

$user_a = $create_user('user_a', 'user_a@companya.com', 'Password123!');
$user_b = $create_user('user_b', 'user_b@companyb.com', 'Password123!');

echo "--- Step 4: Creating Dummy Company Groups (Company A and Company B) ---\n";

$create_group = function($label, $user, $role_id) use ($group_storage, $group_type_id) {
  $groups = $group_storage->loadByProperties([
    'type' => $group_type_id,
    'label' => $label,
  ]);
  if ($groups) {
    $group = reset($groups);
    echo "Found existing group: $label (id: {$group->id()})\n";
  }
  else {
    $group = $group_storage->create([
      'type' => $group_type_id,
      'label' => $label,
      'uid' => $user->id(),
    ]);
    $group_storage->save($group);
    echo "Created group: $label (id: {$group->id()})\n";
  }

  // Ensure member has the specified role
  $membership = $group->getMember($user);
  if (!$membership) {
    $group->addMember($user, ['group_roles' => [$role_id]]);
    echo "Added {$user->getAccountName()} to $label with role $role_id\n";
  }
  else {
    $relationship = $membership->getGroupRelationship();
    $current_roles = array_column($relationship->get('group_roles')->getValue(), 'target_id');
    if (!in_array($role_id, $current_roles)) {
      $current_roles[] = $role_id;
      $relationship->set('group_roles', $current_roles);
      $relationship->save();
      echo "Updated roles for {$user->getAccountName()} in $label\n";
    }
  }

  return $group;
};

$group_a = $create_group('Company A', $user_a, "$group_type_id-company_admin");
$group_b = $create_group('Company B', $user_b, "$group_type_id-company_admin");

echo "--- Step 5: Verifying Access Isolation ---\n";

// User A access to Company A (Own company)
$access_a_own = $group_a->access('view', $user_a);
echo "User A access to Company A: " . ($access_a_own ? "ALLOWED (200 OK)" : "DENIED") . "\n";

// User A access to Company B (Other company)
$access_a_other = $group_b->access('view', $user_a);
echo "User A access to Company B: " . ($access_a_other ? "ALLOWED" : "DENIED (403 Forbidden)") . "\n";

// User B access to Company B (Own company)
$access_b_own = $group_b->access('view', $user_b);
echo "User B access to Company B: " . ($access_b_own ? "ALLOWED (200 OK)" : "DENIED") . "\n";

// User B access to Company A (Other company)
$access_b_other = $group_a->access('view', $user_b);
echo "User B access to Company A: " . ($access_b_other ? "ALLOWED" : "DENIED (403 Forbidden)") . "\n";

// Anonymous access
$anon = new \Drupal\Core\Session\AnonymousUserSession();
$access_anon = $group_a->access('view', $anon);
echo "Anonymous access to Company A: " . ($access_anon ? "ALLOWED" : "DENIED (403 Forbidden)") . "\n";

echo "--- Done Phase 3 Setup! ---\n";
