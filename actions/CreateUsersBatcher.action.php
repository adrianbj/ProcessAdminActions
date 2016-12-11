<?php

class CreateUsersBatcher extends ProcessAdminActions {

    protected $description = 'Allows you to batch create users.';
    protected $notes = 'Having the Email New User module installed and configured to generate a password automatically is recommended. It is also recommended that you install the Password Force Change module.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );


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
                'description' => 'Each line must contain: username, useremail@gmail.com',
                'notes' => 'If you want to set other field values as well, include them in this order: username, '.$this->templates->get("user")->fields->find("name!=roles")->implode(", ", "name"),
                'type' => 'textarea',
                'required' => true
            )
        );
    }


    protected function executeAction($options) {

        $passwordFailureMessage = "If you don't specify a password, the EmailNewUser module has to be installed and the Generate Password option must be checked.";

        if($this->wire('modules')->isInstalled("EmailNewUser")) {
            $this->modules->get("EmailNewUser");
            $emailNewUserSettings = $this->modules->getModuleConfigData('EmailNewUser');
        }
        $newUsersArr = $this->explodeAndTrim($options['newUsers'], "\n");
        foreach($newUsersArr as $newUser) {
            $newUserArr = $this->explodeAndTrim($newUser, ',');
            $_newUser = new User();
            $_newUser->name = $newUserArr[0];
            if(count($newUserArr) == 2) {
                if(!$this->wire('modules')->isInstalled("EmailNewUser") || !$emailNewUserSettings['generatePassword']) {
                    $this->failureMessage = $passwordFailureMessage;
                    return false;
                }
                $_newUser->email = $newUserArr[1];
                $_newUser->pass = ''; // need to set to blank to trigger EmailNewUser to generate automatic password
                $_newUser->sendEmail = true;
                $_newUser->force_passwd_change = 1;
            }
            else {
                $i=1;
                foreach($this->templates->get("user")->fields as $f) {
                    if($f->name == 'roles') continue;
                    if($f->name == 'pass' && $newUserArr[$i] == '' && (!$this->wire('modules')->isInstalled("EmailNewUser") || !$emailNewUserSettings['generatePassword'])) {
                        $this->failureMessage = $passwordFailureMessage;
                        return false;
                    }
                    $_newUser->{$f->name} = isset($newUserArr[$i]) ? $newUserArr[$i] : null;
                    $i++;
                }
            }
            $_newUser->addRole("guest");
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