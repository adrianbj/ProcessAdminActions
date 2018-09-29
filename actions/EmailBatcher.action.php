<?php

class EmailBatcher extends ProcessAdminActions {

    protected $title = 'Email Batcher';
    protected $description = 'Lets you email multiple addresses at once.';
    protected $notes = 'You can select "Pages" or "User Roles" for determining the recipients.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Send Emails';
    protected $icon = 'envelope';

    protected function defineOptions() {

        $rolesOptions = array();
        foreach($this->wire('roles')->find("sort=name") as $role) $rolesOptions[$role->id] = $role->name;

        return array(
            array(
                'name' => 'fromEmail',
                'label' => 'From Email',
                'description' => 'Email Address that the email will come from.',
                'notes' => 'eg. you@gmail.com',
                'type' => 'email',
                'columnWidth' => 50,
                'required' => true
            ),
            array(
                'name' => 'fromName',
                'label' => 'From Name',
                'description' => 'Name that the email will come from.',
                'notes' => 'Your Name',
                'type' => 'text',
                'columnWidth' => 50,
                'required' => true
            ),
            array(
                'name' => 'pages',
                'label' => 'Pages',
                'description' => 'Select the pages that contain the recipients email address.',
                'notes' => 'Only use one of "Pages" or "User Roles"',
                'type' => 'selector',
                'allowSystemCustomFields' => true,
                'allowSystemTemplates' => true,
                'columnWidth' => 50,
                'required' => true,
                'requiredIf' => 'userRoles.count=0'
            ),
            array(
                'name' => 'email',
                'label' => 'Email Address Field',
                'description' => 'Select the field from the selected pages that contains the recipient email addresses.',
                'type' => 'select',
                'columnWidth' => 50,
                'options' => $this->wire('fields')->find("type=FieldtypeEmail")->getArray(),
                'required' => true,
                'requiredIf' => 'pages!=""',
                'showIf' => 'pages!=""'
            ),
            array(
                'name' => 'userRoles',
                'label' => 'User Roles',
                'description' => 'Select the roles for users that will be sent the email.',
                'notes' => 'Only use one of "Pages" or "User Roles"',
                'type' => 'AsmSelect',
                'options' => $rolesOptions,
                'required' => true,
                'requiredIf' => 'pages=""'
            ),
            array(
                'name' => 'testAddress',
                'label' => 'Test Address',
                'description' => 'Enter a test email address.',
                'notes' => 'This will send the results of the parsed version of the first match to this address. If this is populated, no emails will be sent to matched pages / roles.',
                'type' => 'email'
            ),
            array(
                'name' => 'subject',
                'label' => 'Subject',
                'description' => 'Subject of the email.',
                'type' => 'text',
                'columnWidth' => 100,
                'required' => true
            ),
            array(
                'name' => 'body',
                'label' => 'Body',
                'description' => 'If you enter HTML, a text only version will be created automatically and both sent.',
                'notes' => 'You can use any fields from the page template within your email body, eg: Dear {first_name} where "first_name" is a template field. You can also use {fromEmail} and {adminUrl}.',
                'type' => 'CKEditor',
                'usePurifier' => false,
                'useACF' => false,
                'required' => true
            )
        );
    }


    protected function executeAction($options) {

        if($options['testAddress']) $testAddress = $options['testAddress'];

        if($options['userRoles']) {
            $recipients = $this->wire('users')->find("roles=".implode('|', $options['userRoles']));
            $emailField = 'email';
        }
        elseif($options['pages']) {
            $recipients = $this->wire('pages')->find($options['pages']);
            $emailField = $options['email'];
        }

        $i = 1;
        foreach($recipients as $recipient) {
            if(isset($testAddress)) {
                // if a test email, then only send first match from selected Pages or Users Roles
                if(isset($testAddress) && $i > 1) break;
                $toEmail = $testAddress;
            }
            else {
                $toEmail = isset($emailField) ? $recipient->$emailField : $recipient;
            }

            //replace curly braces codes with matching PW field names
            $htmlBody = $options['body'];
            $htmlBody = $this->parseBody($htmlBody, $options['fromEmail'], $recipient);

            $sent = $this->sendNewUserEmail($toEmail, $options['fromEmail'], $options['fromName'], $options['subject'], $htmlBody);
            if($sent) {
                $this->successMessage = $i . ' email were successfully sent.';
            }
            else {
                $this->failureMessage = 'Sorry, no emails could be sent.';
            }
            $i++;
        }
        return true;
    }

    private function sendNewUserEmail($toEmail, $fromEmail, $fromName, $subject, $htmlBody) {
        $mailer = $this->wire('mail')->new();
        $mailer->to($toEmail);
        $mailer->from($fromEmail);
        $mailer->fromName($fromName);
        $mailer->subject($subject);
        $mailer->body($this->parseTextBody($htmlBody));
        $mailer->bodyHTML($htmlBody);
        $sent = $mailer->send();

        return $sent;
    }

    public function ___parseBody($htmlBody, $fromEmail, $recipient) {

        if (preg_match_all('/{([^}]*)}/', $htmlBody, $matches)) {
            foreach ($matches[0] as $match) {
                $field = str_replace(array('{','}'), '', $match);

                if($field == "adminUrl") {
                    $replacement = wire('config')->urls->httpAdmin;
                }
                elseif($field == "fromEmail") {
                    $replacement = $fromEmail;
                }
                elseif($recipient && $recipient->$field) {
                    $replacement = $recipient->$field;
                }
                else {
                    // if no replacement available, add a non-breaking space
                    // this prevents removal of line break when converting to plain text version
                    $replacement = '&nbsp;';
                }

                $htmlBody = str_replace($match, $replacement, $htmlBody);
            }
        }
        return $htmlBody;
    }


    private function parseTextBody($str) {
        $str = wire('sanitizer')->textarea($str);
        $str = $this->text_target($str);
        $str = $this->remove_html_comments($str);
        $str = preg_replace('/(<|>)\1{2}/is', '', $str);
        $str = preg_replace(
            array(// Remove invisible content
                '@<head[^>]*?>.*?</head>@siu',
                '@<style[^>]*?>.*?</style>@siu',
                '@<script[^>]*?.*?</script>@siu',
                '@<noscript[^>]*?.*?</noscript>@siu',
                ),
            "", //replace above with nothing
            $str );
        $str = preg_replace('#(<br */?>\s*)+#i', '<br />', $str);
        $str = strip_tags($str);
        $str = $this->replaceWhitespace($str);
        return $str;
    }


    private function remove_html_comments($str) {
        return preg_replace('/<!--(.|\s)*?-->/', '', $str);
    }


    //convert links to be url in parentheses after linked text
    private function text_target($str) {
        return preg_replace('/<a href="(.*?)">(.*?)<\\/a>/i', '$2 ($1)', str_replace(' target="_blank"','',$str));
    }

    private function replaceWhitespace($str) {
        $result = $str;
        foreach (array(
        "  ", " \t",  " \r",  " \n",
        "\t\t", "\t ", "\t\r", "\t\n",
        "\r\r", "\r ", "\r\t", "\r\n",
        "\n\n", "\n ", "\n\t", "\n\r",
        ) as $replacement) {
        $result = str_replace($replacement, $replacement[0], $result);
        }
        return $str !== $result ? $this->replaceWhitespace($result) : $result;
    }

}