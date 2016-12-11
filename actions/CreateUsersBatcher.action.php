<?php

class CreateUsersBatcher extends ProcessAdminActions {

    protected $description = 'Allows you to batch create users. This module requires the Email New User module and it must be configured to generate a password automatically.';
    protected $notes = 'It is also recommended that you install the Password Force Change module.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected function checkRequirements() {
        if($this->wire('modules')->isInstalled("EmailNewUser")) {
            $emailNewUserSettings = $this->modules->getModuleConfigData('EmailNewUser');
        }
        if(!$this->wire('modules')->isInstalled("EmailNewUser") || !$emailNewUserSettings['generatePassword']) {
            $this->requirementsMessage = 'This action requires the EmailNewUser module to be installed and the Generate Password option to be checked.';
            return false;
        }
        else {
            return true;
        }
    }

    protected function defineOptions() {

        $rolesOptions = array();
        foreach($this->roles as $role) $rolesOptions[$role->id] = $role->name;

        return array(
            array(
                'name' => 'roles',
                'label' => 'Roles',
                'description' => 'Select the roles that you want to applied to all new users.',
                'notes' => 'At least one of these roles must have the "profile-edit" permission so that the user can change their password the first time they log in.',
                'type' => 'AsmSelect',
                'options' => $rolesOptions,
                'required' => true
            ),
            array(
                'name' => 'newUsers',
                'label' => 'New Users',
                'description' => 'Each line should contain: username, email',
                'notes' => 'Example: myname, myname@gmail.com',
                'type' => 'textarea',
                'required' => true
            )
        );
    }


    protected function executeAction($options) {

        $this->modules->get("EmailNewUser");
        $newUsersArr = $this->explodeAndTrim($options['newUsers'], "\n");
        foreach($newUsersArr as $newUser) {
            $newUserArr = $this->explodeAndTrim($newUser, ',');
            $_newUser = new User();
            $_newUser->name = $newUserArr[0];
            $_newUser->email = $newUserArr[1];
            $_newUser->pass = ''; // need to set to blank to trigger EmailNewUser to generate automatic password
            $_newUser->sendEmail = true;
            $_newUser->force_passwd_change = 1;
            foreach($options['roles'] as $role_id) {
                $_newUser->addRole((int)$role_id);
            }
            $_newUser->save();
        }

        $this->successMessage = count($newUsersArr) . ' new users were successfully created.';
        return true;

    }

    private function explodeAndTrim($str, $delimiter) {
        $arr = explode($delimiter, trim($str));
        return array_filter($arr, 'trim'); // remove any extra \r characters left behind
    }

}