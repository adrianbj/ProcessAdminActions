<?php

class UserRolesPermissionsBatcher extends ProcessAdminActions {

    protected $title = 'User Roles Permission Batcher';
    protected $description = 'Lets you add or remove permissions for multiple roles, or roles for multiple users at once.';
    protected $notes = 'Role selections are required. If all three have selections, permissions will be modified in roles and roles modified in users.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Apply Changes';
    protected $icon = 'cogs';

    protected function defineOptions() {

        $userOptions = array();
        foreach($this->wire('users')->find("sort=name") as $u) $usersOptions[$u->id] = $u->name;

        $rolesOptions = array();
        foreach($this->wire('roles')->find("sort=name") as $role) $rolesOptions[$role->id] = $role->name;

        $permissionsOptions = array();
        foreach($this->wire('permissions')->find("sort=name") as $permission) $permissionsOptions[$permission->id] = $permission->name;

        return array(
            array(
                'name' => 'users',
                'label' => 'Users',
                'description' => 'Select the users that you want to manipulate.',
                'notes' => 'A selection is required if no permissions are selected.',
                'type' => 'AsmSelect',
                'options' => $usersOptions,
                'required' => true,
                'requiredIf' => 'permissions.count=0',
            ),
            array(
                'name' => 'roles',
                'label' => 'Roles',
                'description' => 'Select the roles that you want to modify for the selected users.',
                'notes' => 'A selection is always required.',
                'type' => 'AsmSelect',
                'options' => $rolesOptions,
                'required' => true
            ),
            array(
                'name' => 'permissions',
                'label' => 'Permissions',
                'description' => 'Select the permissions that you want to modify for the selected roles.',
                'notes' => 'A selection is required if no users are selected.',
                'type' => 'AsmSelect',
                'options' => $permissionsOptions,
                'required' => true,
                'requiredIf' => 'users.count=0',
            ),
            array(
                'name' => 'addOrRemove',
                'label' => 'Add or Remove',
                'description' => 'Select whether you want to add or remove the selected permissions from roles, and roles from users.',
                'type' => 'radios',
                'options' => array(
                    'add' => 'Add',
                    'remove' => 'Remove'
                ),
                'required' => true,
                'optionColumns' => 1,
                'value' => 'add'
            )
        );
    }


    protected function executeAction($options) {

        foreach($options['users'] as $user_id) {
            $u = $this->wire('users')->get((int)$user_id);
            $u->of(false);
            foreach($options['roles'] as $role_id) {
                if($options['addOrRemove'] == "add") {
                    $u->addRole((int)$role_id);
                }
                else {
                    $u->removeRole((int)$role_id);
                }
            }
            $u->save();
            $u->of(true);
        }

        foreach($options['roles'] as $role_id) {
            $role = $this->wire('roles')->get((int)$role_id);
            $role->of(false);
            foreach($options['permissions'] as $permission) {
                if($options['addOrRemove'] == "add") {
                    $role->addPermission((int)$permission);
                }
                else {
                    $role->removePermission((int)$permission);
                }
            }
            $role->save();
            $role->of(true);
        }

        return true;

    }

}