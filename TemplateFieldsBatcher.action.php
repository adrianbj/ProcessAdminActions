<?php

class TemplateFieldsBatcher extends ProcessAdminActions {

    protected $description = 'Lets you add or remove multiple fields from multiple templates at once.';
    protected $author = 'Adrian Jones';

    protected function defineOptions() {

        $fieldOptions = array();
        foreach($this->fields as $field) {
            if ($field->flags & Field::flagSystem || $field->flags & Field::flagPermanent) continue;
            $fieldOptions[$field->id] = $field->label ? $field->label . ' (' . $field->name . ')' : $field->name;
        }

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
                'name' => 'fields',
                'label' => 'Fields',
                'description' => 'Select the fields that you want to add or remove in the selected templates',
                'type' => 'AsmSelect',
                'options' => $fieldOptions,
                'required' => true
            ),
            array(
                'name' => 'addOrRemove',
                'label' => 'Add or Remove',
                'description' => 'Select whether you want to add or remove the selected fields from the templates',
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
            foreach($options['fields'] as $field) {
                if($options['addOrRemove'] == "add") {
                    $t->fields->add($field);
                }
                else {
                    $t->fields->remove($field);
                }
                $t->fields->save();
            }
            $t->save();
        }

        $templateCount = count($options['templates']);
        $fieldCount = count($options['fields']);
        $this->successMessage = $fieldCount . ' field' . _n('', 's', $fieldCount) . ' ' . _n('was', 'were', $fieldCount) . ' successfully modified in ' . $templateCount . ' template' . _n('', 's', $templateCount);
        return true;

    }

}