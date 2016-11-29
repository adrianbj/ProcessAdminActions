<?php

class SearchAndReplace extends ProcessAdminActions {

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
                'name' => 'field',
                'label' => 'Field',
                'description' => 'Choose the field that you want to search',
                'type' => 'select',
                'required' => true,
                'options' => $this->fields->find("type=FieldtypeText|TextareaLanguage|FieldtypeTextarea|FieldtypeTextareaLanguage|FieldtypeEmail")->getArray()
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

        $count = 0;
        foreach($this->pages->find($options['selector']) as $p) {
            $p->of(false);
            foreach($options['field'] as $field) {
                $f = $this->fields->get($field);
                $p->$f = str_replace($options['search'], $options['replace'], $p->$f);
                $p->save($f);
            }
            $count++;
        }

        $this->successMessage = 'The ' . $options['action'] . ' action was successfully applied to ' . $count .' page' . _n('', 's', $count) . '.';
        return true;

    }

}