<?php

class CopyTableFieldRowsToOtherPage extends ProcessAdminActions {

    protected $description = 'Add the rows from a Table field on one page to the same field on another page.';
    protected $notes = 'If the field on the destination page already has rows, you can choose to append, or overwrite.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

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
            ),
            array(
                'name' => 'appendOverwrite',
                'label' => 'Append or Overwrite',
                'description' => 'Should the rows be appended to existing rows, or overwrite?',
                'type' => 'radios',
                'required' => true,
                'options' => array(
                    'append' => 'Append',
                    'overwrite' => 'Overwrite'
                ),
                'optionColumns' => 1,
                'value' => 'append'
            )
        );
    }


    protected function executeAction($options) {

        $sourcePage = $this->pages->get((int)$options['sourcePage']);
        $destinationPage = $this->pages->get((int)$options['destinationPage']);
        $tableField = $this->fields->get($this->sanitizer->fieldName($options['tableField']));
        $tableFieldName = $tableField->name;
        $tableFieldType = $tableField->type;

        $sourcePage->of(false);
        $totalRows = $sourcePage->$tableFieldName->getTotal();
        $destinationPage->of(false);

        $cnt = 0;
        $paginationLimit = $tableField->get('paginationLimit');

        if($paginationLimit) {
            $tableRows = new TableRows($destinationPage, $tableField);
        } else {
            $tableRows = $destinationPage->$tableFieldName;
        }

        if($options['appendOverwrite'] == 'overwrite') {
            if($paginationLimit) {
                $tableFieldType->deletePageField($destinationPage, $tableField);
            } else {
                $destinationPage->$tableFieldName->removeAll();
            }
            $destinationPage->save($tableFieldName);
        }

        foreach($sourcePage->$tableFieldName("limit=".$totalRows) as $row) {
            $tableEntry = array();
            foreach($sourcePage->$tableFieldName->columns as $col) {
                $tableEntry[$col['name']] = $row->{$col['name']};
            }
            $item = $tableRows->new($tableEntry);
            $tableRows->add($item);
            if($paginationLimit && ++$cnt >= $paginationLimit) {
                $tableFieldType->savePageFieldRows($destinationPage, $tableField, $tableRows);
                $tableRows = new TableRows($destinationPage, $tableField);
                $cnt = 0;
            }
        }

        if($paginationLimit) {
            if($cnt) $tableFieldType->savePageFieldRows($destinationPage, $tableField, $tableRows);
        } else {
            $destinationPage->set($tableFieldName, $tableRows);
            $destinationPage->save($tableFieldName);
        }

        $this->successMessage = 'The contents of the ' . $tableFieldName . ' field were successfully copied from the ' . $sourcePage->path . ' page to the ' . $destinationPage->path . ' page.';
        return true;

    }

}