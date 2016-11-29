<?php

class CopyTableFieldRowsToOtherPage extends ProcessAdminActions {

    protected $description = 'Add the rows from a Table field on one page to the same field on another page.';

    protected function checkRequirements() {
        if(!$this->wire('modules')->isInstalled("FieldtypeTable")) {
            $this->error('The Table field type is not currently installed.');
            return false;
        }
        else {
            return true;
        }
    }

    protected function defineOptions() {
        return array(
            array(
                'name' => 'tableField',
                'label' => 'Table Field',
                'description' => 'Choose the Table field that you want to copy',
                'type' => 'select',
                'required' => true,
                'options' => $this->fields->find("type=FieldtypeTable")->getArray()
            ),
            array(
                'name' => 'sourcePage',
                'label' => 'Source Page',
                'description' => 'The source page for the contents of the table field',
                'type' => 'pageListSelect',
                'required' => true
            ),
            array(
                'name' => 'destinationPage',
                'label' => 'Destination Page',
                'description' => 'The destination page for the contents of the table field',
                'type' => 'pageListSelect',
                'required' => true
            )
        );
    }


    protected function executeAction($options) {

        $sourcePage = $this->pages->get((int)$options['sourcePage']);
        $destinationPage = $this->pages->get((int)$options['destinationPage']);
        $tableFieldName = $this->fields->get($this->sanitizer->fieldName($options['tableField']))->name;

        $destinationPage->of(false);
        foreach($sourcePage->getUnformatted($tableFieldName) as $row) {
            $newRow = $destinationPage->$tableFieldName->makeBlankItem();
            foreach($sourcePage->$tableFieldName->columns as $col) {
                $newRow->{$col['name']} = $row->{$col['name']};
            }
            $destinationPage->$tableFieldName->add($newRow);
        }
        $destinationPage->save($tableFieldName);

        $this->successMessage = 'The contents of the ' . $tableFieldName . ' field were successfully copied from the ' . $sourcePage->path . ' page to the ' . $destinationPage->path . ' page.';
        return true;

    }

}