<?php

class PageActiveLanguagesBatcher extends ProcessAdminActions {

    protected $description = 'Lets you enable or disable active status of multiple languages on multiple pages at once.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected function defineOptions() {

        $languageOptions = array();
        foreach($this->languages->find("name!=default") as $language) {
            $languageOptions[$language->id] = $language->title ? $language->name . ' (' . $language->title . ')' : $language->name;
        }

        return array(
            array(
                'name' => 'selector',
                'label' => 'Selector',
                'description' => 'Define selector to match the pages you want to manipulate',
                'type' => 'selector',
                'required' => true
            ),
            array(
                'name' => 'languages',
                'label' => 'Languages',
                'description' => 'Select the languages that you want to manipulate',
                'type' => 'checkboxes',
                'required' => true,
                'options' => $languageOptions
            ),
            array(
                'name' => 'activeStatus',
                'label' => 'Active Status',
                'description' => 'Select whether you want to check or uncheck the "Active" checkbox for the selected languages on the matched pages.',
                'type' => 'radios',
                'options' => array(
                    '1' => 'Check',
                    '0' => 'Uncheck'
                ),
                'required' => true,
                'optionColumns' => 1,
                'value' => '1'
            )
        );
    }


    protected function executeAction($options) {

        $count = 0;
        foreach($this->pages->find($options['selector']) as $p) {
            foreach($options['languages'] as $language) {
                $p->set("status$language", $options['activeStatus']);
            }
            $p->save();
            $count++;
        }

        $this->successMessage = 'The actions were successfully applied to ' . $count .' page' . _n('', 's', $count) . '.';
        return true;

    }

}