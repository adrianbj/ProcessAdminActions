<?php

class TemplateRolesBatcher extends ProcessAdminActions {

    protected $description = 'Lets you add or remove access permissions, for multiple roles and multiple templates at once.';
    protected $notes = 'Permission options are: Edit, Create, and Add.';
    protected $author = 'Adrian Jones';

    protected function defineOptions() {

        $rolesOptions = array();
        foreach($this->roles as $role) $rolesOptions[$role->id] = $role->name;

        return array(
            array(
                'name' => 'templates',
                'label' => 'Templates',
                'description' => 'Select the templates that you want to manipulate',
                'type' => 'AsmSelect',
                'required' => true,
                'options' => $this->templates->find("sort=name, flags!=".Template::flagSystem)->getArray()
            ),
            array(
                'name' => 'roles',
                'label' => 'Roles',
                'description' => 'Select the roles that you want to modify in the selected templates',
                'type' => 'AsmSelect',
                'options' => $rolesOptions,
                'required' => true
            ),
            array(
                'name' => 'access',
                'label' => 'Access',
                'description' => 'Select the access permissions that you want the selected roles added or removed from',
                'type' => 'checkboxes',
                'options' => array(
                    'editRoles' => 'Edit',
                    'createRoles' => 'Create',
                    'addRoles' => 'Add'
                ),
                'required' => true
            ),
            array(
                'name' => 'addOrRemove',
                'label' => 'Add or Remove',
                'description' => 'Select whether you want to add or remove the selected access/roles or fields from the templates',
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

        foreach($options['templates'] as $template) {

            $t = $this->templates->get($template);

            $allRoles = array();
            foreach($options['access'] as $access) {
                $allRoles = $t->$access; //already set roles
                foreach($options['roles'] as $role) {
                    if($options['addOrRemove'] == "add") {
                        if($access == "createRoles" && !in_array($role, $t->editRoles)) continue; //in case they check to add Create without Edit which is not allowed
                        if($this->roles->get($role)->hasPermission("page-edit")) $allRoles[] = $role; // page-edit check shouldn't actually be necessary here since list of available roles is already limited to these anyway
                    }
                    else {
                        if($access == "editRoles"){ //in case they check to remove Edit, then automatically remove Create because this is not allowed
                            $createRoles = $t->createRoles;
                            if (($key = array_search($role, $createRoles)) !== false) {
                                unset($createRoles[$key]);
                            }
                            $t->createRoles = array_unique($createRoles);
                            $t->save();
                        }
                        if (($key = array_search($role, $allRoles)) !== false) {
                            unset($allRoles[$key]);
                        }
                    }
                }
                $t->useRoles = 1;
                $t->roles = $this->pages->get($this->config->rolesPageID)->children("name!=superuser");
                $t->$access = array_unique($allRoles); // remove duplicates in case user tries to add role that is already set
            }

            $t->save();

        }

        return true;

    }

}