<?php

class CopyFieldContentToOtherPage extends ProcessAdminActions {

    protected $title = 'Copy Field Content to Other Page';
    protected $description = 'Copies the content from a field on one page to the same field on another page.';
    protected $notes = 'This can be useful if you decide to restructure where certain content lives on the site.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Copy Content';
    protected $icon = 'copy';

    protected function defineOptions() {
        return array(
            array(
                'name' => 'field',
                'label' => 'Field',
                'description' => 'Choose the field that you want to copy',
                'type' => 'select',
                'required' => true,
                'options' => $this->wire('fields')->find("sort=name")->getArray()
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

        $fieldName = $this->wire('fields')->get((int)$options['field'])->name;
        $sourcePage = $this->wire('pages')->get((int)$options['sourcePage']);
        $destinationPage = $this->wire('pages')->get((int)$options['destinationPage']);

        $sourcePage->of(false);
        $destinationPage->of(false);
        $destinationPage->$fieldName = $sourcePage->$fieldName;
        $destinationPage->save($fieldName);

        $this->successMessage = 'The contents of the ' . $fieldName . ' field were successfully copied from the ' . $sourcePage->path . ' page to the ' . $destinationPage->path . ' page.';
        return true;

    }

}