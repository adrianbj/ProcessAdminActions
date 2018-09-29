<?php

class CopyTableFieldRowsToOtherPage extends ProcessAdminActions {

    protected $title = 'Copy Table Field Rows to Other Page';
    protected $description = 'Add the rows from a Table field on one page to the same field on another page.';
    protected $notes = 'If the field on the destination page already has rows, you can choose to append, or overwrite.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Copy Table Rows';
    protected $icon = 'copy';

    protected function checkRequirements() {
        if(!$this->wire('modules')->isInstalled("FieldtypeTable")) {
            $this->requirementsMessage = 'The Table field type is not currently installed.';
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
                'options' => $this->wire('fields')->find("type=FieldtypeTable")->getArray()
            ),
            array(
                'name' => 'tableRowSelector',
                'label' => 'Table Row Selector',
                'description' => 'Optional selector to limit table rows. Leave empty to select all rows.',
                'notes' => 'eg. price>50',
                'type' => 'text'
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

        $tableField = $this->wire('fields')->get((int)$options['tableField']);
        $tableFieldName = $tableField->name;
        $tableFieldType = $tableField->type;
        $sourcePage = $this->wire('pages')->get((int)$options['sourcePage']);
        $destinationPage = $this->wire('pages')->get((int)$options['destinationPage']);

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

        if($options['tableRowSelector'] != '') {
            $selectedRows = $sourcePage->$tableFieldName("limit=$totalRows, {$options['tableRowSelector']}");
        }
        else {
            $selectedRows = $sourcePage->$tableFieldName("limit=".$totalRows);
        }

        foreach($selectedRows as $row) {
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