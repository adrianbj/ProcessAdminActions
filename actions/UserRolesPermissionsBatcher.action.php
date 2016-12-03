<?php

class UserRolesPermissionsBatcher extends ProcessAdminActions {

    protected $description = 'Lets you add or remove permissions for multiple roles, or roles for multiple users at once.';
    protected $notes = 'Role selections are required. If all three have selections, permissions will be modified in roles and roles modified in users.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected function defineOptions() {

        $userOptions = array();
        foreach($this->users->find("sort=name") as $u) $usersOptions[$u->id] = $u->name;

        $rolesOptions = array();
        foreach($this->roles->find("sort=name") as $role) $rolesOptions[$role->id] = $role->name;

        $permissionsOptions = array();
        foreach($this->permissions->find("sort=name") as $permission) $permissionsOptions[$permission->id] = $permission->name;

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

        foreach($options['users'] as $uid) {
            $u = $this->users->get($uid);
            foreach($options['roles'] as $role) {
                if($options['addOrRemove'] == "add") {
                    $u->addRole($role);
                }
                else {
                    $u->removeRole($role);
                }
            }
            $u->save();
        }

        foreach($options['roles'] as $rid) {
            $role = $this->roles->get($rid);
            foreach($options['permissions'] as $permission) {
                if($options['addOrRemove'] == "add") {
                    $role->addPermission($permission);
                }
                else {
                    $role->removePermission($permission);
                }
            }
            $role->save();
        }

        return true;

    }

}