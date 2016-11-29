<?php

class CopyFieldContentToOtherPage extends ProcessAdminActions {

    protected $description = 'Copies the content from a field on one page to the same field on another page.';
    protected $notes = 'This can be useful if you decide to restructure where certain content lives on the site.';

    protected function defineOptions() {
        return array(
            array(
                'name' => 'field',
                'label' => 'Field',
                'description' => 'Choose the field that you want to copy',
                'type' => 'select',
                'required' => true,
                'options' => $this->fields->find("sort=name")->getArray()
            ),
            array(
                'name' => 'sourcePage',
                'label' => 'Source Page',
                'description' => 'The source page for the contents of the field',
                'type' => 'pageListSelect',
                'required' => true
            ),
            array(
                'name' => 'destinationPage',
                'label' => 'Destination Page',
                'description' => 'The destination page for the contents of the field',
                'type' => 'pageListSelect',
                'required' => true
            )
        );
    }


    protected function executeAction($options) {

        $sourcePage = $this->pages->get((int)$options['sourcePage']);
        $destinationPage = $this->pages->get((int)$options['destinationPage']);
        $fieldName = $this->fields->get($this->sanitizer->fieldName($options['field']))->name;

        $sourcePage->of(false);
        $destinationPage->of(false);
        $destinationPage->$fieldName = $sourcePage->$fieldName;
        $destinationPage->save($fieldName);

        $this->successMessage = 'The contents of the ' . $fieldName . ' field were successfully copied from the ' . $sourcePage->path . ' page to the ' . $destinationPage->path . ' page.';
        return true;

    }

}