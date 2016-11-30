<?php

class SearchAndReplace extends ProcessAdminActions {

    protected $author = 'Adrian Jones';

    protected function defineOptions() {
        return array(
            array(
                'name' => 'selector',
                'label' => 'Selector',
                'description' => 'Define selector to match the pages you want to manipulate',
                'type' => 'selector',
                'required' => true
            ),
            array(
                'name' => 'fields',
                'label' => 'Fields',
                'description' => 'Choose the field(s) that you want to search',
                'type' => 'AsmSelect',
                'required' => true,
                'options' => $this->fields->find("type=FieldtypeText|TextareaLanguage|FieldtypeTextarea|FieldtypeTextareaLanguage")->getArray()
            ),
            array(
                'name' => 'search',
                'label' => 'Search',
                'description' => 'Enter text to search for',
                'type' => 'text',
                'required' => true
            ),
            array(
                'name' => 'replace',
                'label' => 'Replace',
                'description' => 'Enter text to replace',
                'type' => 'text',
                'required' => true
            )
        );
    }


    protected function executeAction($options) {

        bd($options);
        $count = 0;
        foreach($this->pages->find($options['selector']) as $p) {
            $p->of(false);
            foreach($options['fields'] as $field) {
                $fieldName = $this->fields->get($field)->name;
                bd($p->$fieldName);
                $p->$fieldName = str_replace($options['search'], $options['replace'], $p->$fieldName);
                $p->save($fieldName);
            }
            $count++;
        }

        $this->successMessage = 'Successfully applied to ' . $count .' page' . _n('', 's', $count) . '.';
        return true;

    }

}