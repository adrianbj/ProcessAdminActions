<?php

class PageActiveLanguagesBatcher extends ProcessAdminActions {

    protected $title = 'Page Active Languages Batcher';
    protected $description = 'Lets you enable or disable active status of multiple languages on multiple pages at once.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Adjust Language Statuses';
    protected $icon = 'language';

    protected function checkRequirements() {
        if(!$this->wire('modules')->isInstalled("LanguageSupport")) {
            $this->requirementsMessage = 'Language support is not enabled, so this module can not do anything.';
            return false;
        }
        else {
            return true;
        }
    }

    protected function defineOptions() {

        $selector = array(
            array(
            'name' => 'selector',
            'label' => 'Selector',
            'description' => 'Define selector to match the pages you want to manipulate',
            'type' => 'selector',
            'required' => true
            )
        );

        $languageStatus = array();
        foreach($this->wire('languages')->find("name!=default") as $language) {
            $languageStatus[] = array(
                'name' => 'language'.$language,
                'label' => $language->title,
                'type' => 'radios',
                'options' => array(
                    'nochange' => 'No Change',
                    '1' => 'Check Active',
                    '0' => 'Uncheck Active',
                ),
                'required' => true,
                'optionColumns' => 1,
                'value' => 'nochange'
            );
        }

        $languageFieldset = array(
            array(
                'name' => 'languages',
                'label' => 'Languages',
                'description' => 'Select whether you want to check or uncheck the "Active" checkbox for the selected languages on the matched pages.',
                'type' => 'fieldset',
                'children' => $languageStatus
            )
        );

        return array_merge($selector, $languageFieldset);
    }


    protected function executeAction($options) {
        $count = 0;
        foreach($this->wire('pages')->find($options['selector']) as $p) {
            foreach($this->wire('languages')->find("name!=default") as $language) {
                $newLanguageStatus = $options['language'.$language->id];
                if($newLanguageStatus !== 'nochange') $p->set("status".$language->id, $options['language'.$language->id]);
            }
            $p->save();
            $count++;
        }

        $this->successMessage = 'The actions were successfully applied to ' . $count .' page' . _n('', 's', $count) . '.';
        return true;

    }

}