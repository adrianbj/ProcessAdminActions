<?php

class CreateUsersBatcher extends ProcessAdminActions {

    protected $title = 'Create Users Batcher';
    protected $description = 'Allows you to batch create users.';
    protected $notes = 'Having the Email New User module installed and configured to generate a password automatically is recommended. It is also recommended that you install the Password Force Change module.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Create Users';
    protected $icon = 'user-plus';

    protected function defineOptions() {

        $rolesOptions = array();
        foreach($this->wire('roles') as $role) $rolesOptions[$role->id] = $role->name;

        $this->userFields = $this->wire('templates')->get("user")->fields->find("name!=roles");

        return array(
            array(
                'name' => 'roles',
                'label' => 'Roles',
                'description' => 'Select the roles that you want to applied to all new users.',
                'notes' => ($this->wire('modules')->isInstalled("EmailNewUser") && $this->wire('modules')->isInstalled("PasswordForceChange") ? 'You have the Email New User and Password Force Change modules installed. If you are automatically generating passwords, at least one of these roles must have the "profile-edit" permission so that the user can change their password the first time they log in.' : ''),
                'type' => 'AsmSelect',
                'options' => $rolesOptions,
                'required' => true
            ),
            array(
                'name' => 'newUsers',
                'label' => 'New Users',
                'description' => 'Each line must contain: name, useremail@gmail.com',
                'notes' => 'Use CSV or JSON. If you want to set other field values as well, include them in the CSV in this order or as the JSON key: name, '.$this->userFields->implode(", ", "name"),
                'type' => 'textarea',
                'required' => true
            )
        );
    }


    protected function executeAction($options) {

        $passwordFailureMessage = "If you don't specify a password, the EmailNewUser module has to be installed and the Generate Password option must be checked.";

        if($this->wire('modules')->isInstalled("EmailNewUser")) {
            $this->wire('modules')->get("EmailNewUser");
            $emailNewUserSettings = $this->wire('modules')->getModuleConfigData('EmailNewUser');
        }

        // if there is no new line at the end, add one to fix issue if last item in CSV row has enclosures but others don't
        $newUsers = $options['newUsers'];

        if($this->isJSON($newUsers)) {
            $newUsersArray = json_decode($newUsers, true);
            $fieldNames = array_values($this->userFields->explode('name'));
            array_unshift($fieldNames, "name");
        }
        else {

            if(substr($newUsers, -1) != "\r" && substr($newUsers, -1) != "\n") $newUsers .= PHP_EOL;

            require_once __DIR__ . '/libraries/parsecsv-for-php/parsecsv.lib.php';

            $userPagesArray = new parseCSV();
            $userPagesArray->encoding('UTF-16', 'UTF-8');
            $userPagesArray->heading = false;
            $userPagesArray->delimiter = ',';
            $userPagesArray->enclosure = '"';
            $userPagesArray->parse($newUsers);

            $newUsersArray = $userPagesArray->data;
        }

        //iterate through rows of users checking for duplicates / existing users
        $userNames = array();
        $existingUserNames = array();
        foreach($newUsersArray as $newUserArr) {
            $userNames[] = $newUserArr[0];
            if($this->wire('users')->get($newUserArr[0])->id) $existingUserNames[] = $newUserArr[0];
        }

        if(count($existingUserNames) > 0) {
            $this->failureMessage = 'There are existing usernames ('.implode(', ', $existingUserNames).') in the CSV. Please correct and try again.';
            return false;
        }

        $uniqueUserNames = array_unique($userNames);
        $counts = array_count_values($uniqueUserNames);
        $duplicateUserNames = array_filter($userNames, function($o) use (&$counts) {
            return empty($counts[$o]) || !$counts[$o]--;
        });

        if(count($userNames) !== count($uniqueUserNames)) {
            $this->failureMessage = 'There are duplicate usernames ('.implode(', ', $duplicateUserNames).') in the CSV. Please correct and try again.';
            return false;
        }

        // if we get past duplicate / existing user checks loop again to add users
        foreach($newUsersArray as $newUserArr) {

            // if $fieldNames array isset we are dealing with JSON, so order to match order of fields in the user template
            if(isset($fieldNames)) $newUserArr = array_values(array_merge(array_flip($fieldNames), $newUserArr));

            $_newUser = new User();
            $_newUser->name = $this->wire('sanitizer')->pageName($newUserArr[0]);
            if(count($newUserArr) == 2) {
                if(!$this->wire('modules')->isInstalled("EmailNewUser") || !$emailNewUserSettings['generatePassword']) {
                    $this->failureMessage = $passwordFailureMessage;
                    return false;
                }
                $_newUser->email = $this->wire('sanitizer')->email($newUserArr[1]);
                $_newUser->pass = ''; // need to set to blank to trigger EmailNewUser to generate automatic password
                $_newUser->sendEmail = true;
                $_newUser->force_passwd_change = 1;
            }
            else {
                $i=1;
                foreach($this->wire('templates')->get("user")->fields as $f) {
                    if($f->name == 'roles') continue;
                    if($f->name == 'pass' && $newUserArr[$i] == '' && (!$this->wire('modules')->isInstalled("EmailNewUser") || !$emailNewUserSettings['generatePassword'])) {
                        $this->failureMessage = $passwordFailureMessage;
                        return false;
                    }
                    if($this->wire('fields')->get($f->name)->type instanceof FieldtypeFile) {
                        // need to save before adding files/images
                        $_newUser->save();
                        if(isset($newUserArr[$i])) $_newUser->{$f->name}->add($newUserArr[$i]);
                    }
                    else {
                        $_newUser->{$f->name} = isset($newUserArr[$i]) ? $newUserArr[$i] : null;
                    }

                    $i++;
                }
            }
            $_newUser->addRole("guest");
            foreach($options['roles'] as $role_id) {
                $_newUser->addRole((int)$role_id);
            }
            $_newUser->save();
        }

        $this->successMessage = count($newUsersArray) . ' new users were successfully created.';
        return true;

    }

    private function isJSON($string){
        call_user_func_array('json_decode',func_get_args());
        return (json_last_error()===JSON_ERROR_NONE);
    }

}