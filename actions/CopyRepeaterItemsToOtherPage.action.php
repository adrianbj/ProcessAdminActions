<?php

class CopyRepeaterItemsToOtherPage extends ProcessAdminActions {

    protected $description = 'Add the items from a Repeater field on one page to the same field on another page.';
    protected $notes = 'If the field on the destination page already has items, you can choose to append, or overwrite.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected function checkRequirements() {
        if(!$this->wire('modules')->isInstalled("FieldtypeRepeater")) {
            $this->error('The Repeater field type is not currently installed.');
            return false;
        }
        else {
            return true;
        }
    }

    protected function defineOptions() {
        return array(
            array(
                'name' => 'repeaterField',
                'label' => 'Repeater Field',
                'description' => 'Choose the Repeater field that you want to copy',
                'type' => 'select',
                'required' => true,
                'options' => $this->fields->find("type=FieldtypeRepeater")->getArray()
            ),
            array(
                'name' => 'repeaterItemSelector',
                'label' => 'Repeater Item Selector',
                'description' => 'Optional selector to limit repeater items. Leave empty to select all items.',
                'notes' => 'eg. price>50',
                'type' => 'text'
            ),
            array(
                'name' => 'sourcePage',
                'label' => 'Source Page',
                'description' => 'The source page for the contents of the repeater field',
                'type' => 'pageListSelect',
                'required' => true
            ),
            array(
                'name' => 'destinationPage',
                'label' => 'Destination Page',
                'description' => 'The destination page for the contents of the repeater field',
                'type' => 'pageListSelect',
                'required' => true
            ),
            array(
                'name' => 'appendOverwrite',
                'label' => 'Append or Overwrite',
                'description' => 'Should the items be appended to existing items, or overwrite?',
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

        $repeaterField = $this->fields->get((int)$options['repeaterField']);
        $repeaterFieldName = $repeaterField->name;
        $repeaterFieldType = $repeaterField->type;
        $sourcePage = $this->pages->get((int)$options['sourcePage']);
        $destinationPage = $this->pages->get((int)$options['destinationPage']);

        $sourcePage->of(false);
        $destinationPage->of(false);

        if($options['appendOverwrite'] == 'overwrite') {
            $destinationPage->$repeaterFieldName->removeAll();
            $destinationPage->save($repeaterFieldName);
        }

        if($options['repeaterItemSelector'] != '') {
            $repeaterItems = $sourcePage->$repeaterFieldName->find("{$options['repeaterItemSelector']}");
        }
        else {
            $repeaterItems = $sourcePage->$repeaterFieldName;
        }

        foreach($repeaterItems as $item) {
            $repeaterItemClone = $destinationPage->$repeaterFieldName->getNew();
            $repeaterItemClone->save();

            foreach($this->fields->get($repeaterFieldName)->repeaterFields as $subfield) {
                $subFieldName = $this->fields->get($subfield)->name;
                $repeaterItemClone->$subFieldName = $item->$subFieldName;
            }

            $repeaterItemClone->save();
        }

        $this->successMessage = 'The contents of the ' . $repeaterFieldName . ' field were successfully copied from the ' . $sourcePage->path . ' page to the ' . $destinationPage->path . ' page.';
        return true;

    }

}