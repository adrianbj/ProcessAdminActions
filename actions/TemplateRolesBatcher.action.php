<?php

class TemplateRolesBatcher extends ProcessAdminActions {

    protected $title = 'Template Roles Batcher';
    protected $description = 'Lets you add or remove access permissions, for multiple roles and multiple templates at once.';
    protected $notes = 'Access permission options are: Edit Pages, Create Pages, and Add Children.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Apply Roles Changes';
    protected $icon = 'cubes';

    protected function defineOptions() {

        $rolesOptions = array();
        foreach($this->wire('roles') as $role) $rolesOptions[$role->id] = $role->name;

        return array(
            array(
                'name' => 'templates',
                'label' => 'Templates',
                'description' => 'Select the templates that you want to manipulate',
                'type' => 'AsmSelect',
                'required' => true,
                'options' => $this->wire('templates')->find("sort=name, flags!=".Template::flagSystem)->getArray()
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
                'name' => 'accessPermissions',
                'label' => 'Access Permissions',
                'description' => 'Select whether you want to add or remove the following access permissions for the selected template and roles.',
                'type' => 'fieldset',
                'children' => array(
                    array(
                        'name' => 'editRoles',
                        'label' => 'Edit Pages',
                        'type' => 'radios',
                        'options' => array(
                            'nochange' => 'No Change',
                            'add' => 'Add',
                            'remove' => 'Remove'
                        ),
                        'required' => true,
                        'optionColumns' => 1,
                        'value' => 'nochange'
                    ),
                    array(
                        'name' => 'createRoles',
                        'label' => 'Create Pages',
                        'type' => 'radios',
                        'options' => array(
                            'nochange' => 'No Change',
                            'add' => 'Add',
                            'remove' => 'Remove'
                        ),
                        'required' => true,
                        'optionColumns' => 1,
                        'value' => 'nochange'
                    ),
                    array(
                        'name' => 'addRoles',
                        'label' => 'Add Children',
                        'type' => 'radios',
                        'options' => array(
                            'nochange' => 'No Change',
                            'add' => 'Add',
                            'remove' => 'Remove'
                        ),
                        'required' => true,
                        'optionColumns' => 1,
                        'value' => 'nochange'
                    )
                )
            )
        );
    }


    protected function executeAction($options) {

        foreach($options['templates'] as $template_id) {

            $template = $this->wire('templates')->get((int)$template_id);

            $allRoles = array();
            foreach(array('editRoles', 'createRoles', 'addRoles') as $access) {
                $allRoles = $template->$access; //already set roles
                foreach($options['roles'] as $role_id) {
                    $role_id = (int)$role_id;
                    if($options[$access] == "add") {
                        if($access == "createRoles" && !in_array($role_id, $template->editRoles)) continue; //in case they check to add Create without Edit which is not allowed
                        if($this->wire('roles')->get($role_id)->hasPermission("page-edit")) $allRoles[] = $role_id; // page-edit check shouldn't actually be necessary here since list of available roles is already limited to these anyway
                    }
                    elseif($options[$access] == "remove") {
                        if($access == "editRoles"){ //in case they check to remove Edit, then automatically remove Create because this is not allowed
                            $createRoles = $template->createRoles;
                            if (($key = array_search($role_id, $createRoles)) !== false) {
                                unset($createRoles[$key]);
                            }
                            $template->createRoles = array_unique($createRoles);
                            $template->save();
                        }
                        if (($key = array_search($role_id, $allRoles)) !== false) {
                            unset($allRoles[$key]);
                        }
                    }
                }
                $template->useRoles = 1;
                $template->roles = $this->wire('pages')->get($this->wire('config')->rolesPageID)->children("name!=superuser");
                $template->$access = array_unique($allRoles); // remove duplicates in case user tries to add role that is already set
            }

            $template->save();

        }

        return true;

    }

}