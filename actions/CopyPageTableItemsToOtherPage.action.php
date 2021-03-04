<?php

class CopyPageTableItemsToOtherPage extends ProcessAdminActions {

    protected $title = 'Copy or Move PageTable Items to Other Page';
    protected $description = 'Add the items from a PageTable field on one page to the same field on another page and optionally remove them from the original page.';
    protected $notes = 'If the field on the destination page already has items, you can choose to append, or overwrite.';
    protected $author = 'Jozsef Juhasz';
    protected $authorLinks = array(
        'pwforum' => '2990-jozsef'
    );

    protected $executeButtonLabel = 'Copy / Move PageTable Items';
    protected $icon = 'copy';

    protected function checkRequirements() {
        if(!$this->wire('modules')->isInstalled("FieldtypePageTable")) {
            $this->wire()->error('The PageTable field type is not currently installed.');
            return false;
        }
        else {
            return true;
        }
    }

    protected function defineOptions() {
        return array(
            array(
                'name' => 'PageTableField',
                'label' => 'PageTable Field',
                'description' => 'Choose the PageTable field that you want to move',
                'type' => 'select',
                'required' => true,
                'options' => $this->wire('fields')->find("type=FieldtypePageTable")->getArray()
            ),
            array(
                'name' => 'PageTableItemSelector',
                'label' => 'PageTable Item Selector',
                'description' => 'Optional selector to limit PageTable items. Leave empty to select all items.',
                'notes' => 'eg. price>50',
                'type' => 'text'
            ),
            array(
                'name' => 'sourcePage',
                'label' => 'Source Page',
                'description' => 'The source page for the contents of the PageTable field',
                'type' => 'pageListSelect',
                'required' => true
            ),
            array(
                'name' => 'destinationPage',
                'label' => 'Destination Page',
                'description' => 'The destination page for the contents of the PageTable field',
                'type' => 'pageListSelect',
                'required' => true
            ),
            array(
                'name' => 'copyMove',
                'label' => 'Copy or Move',
                'description' => 'Should the items be copied or moved (deleted from the source page)?',
                'type' => 'radios',
                'required' => true,
                'options' => array(
                    'copy' => 'Copy',
                    'move' => 'Move'
                ),
                'optionColumns' => 1,
                'value' => 'copy'
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

        $pageTableField = $this->wire('fields')->get((int)$options['PageTableField']);
        $pageTableFieldName = $pageTableField->name;
        $sourcePage = $this->wire('pages')->get((int)$options['sourcePage']);
        if(!$sourcePage->fields->get($pageTableFieldName)) {
            $this->failureMessage = 'Field ' . $pageTableFieldName . ' does not exist on page ' . $sourcePage->path;
            return false;
        }
        $destinationPage = $this->wire('pages')->get((int)$options['destinationPage']);
        if(!$destinationPage->fields->get($pageTableFieldName)) {
            $this->failureMessage = 'Field ' . $pageTableFieldName . ' does not exist on page ' . $destinationPage->path;
            return false;
        }

        $sourcePage->of(false);
        $destinationPage->of(false);

        if($options['appendOverwrite'] == 'overwrite') {
            $destinationPage->$pageTableFieldName->removeAll();
            $destinationPage->save($pageTableFieldName);
        }

        if($options['PageTableItemSelector'] != '') {
            $pageTableItems = $sourcePage->$pageTableFieldName->find("{$options['PageTableItemSelector']}");
        }
        else {
            $pageTableItems = $sourcePage->$pageTableFieldName;
        }

        foreach($pageTableItems as $item) {
            if($pageTableField->parent_id === 0) {
                if($options['copyMove'] == 'move') {
                    $item->parent = $destinationPage->id;
                    $item->save();
                    $destinationPage->$pageTableFieldName->add($item);
                    $destinationPage->save($pageTableField);
                }
                else {
                    $np = $this->wire('pages')->clone($item);
                    $np->of(false);
                    $np->title = $item->title;
                    $np->parent = $destinationPage->id;
                    $np->save();
                    $destinationPage->$pageTableFieldName->add($np);
                    $destinationPage->save($pageTableField);
                }
            }
            else {
                if($options['copyMove'] == 'copy') {
                    $np = $this->wire('pages')->clone($item);
                    $np->of(false);
                    $np->title = $item->title;
                    $np->save();
                    $destinationPage->$pageTableFieldName->add($np);
                    $destinationPage->save($pageTableField);
                }
                else {
                    $destinationPage->$pageTableFieldName->add($item);
                    $destinationPage->save($pageTableField);
                }

            }

            if($options['copyMove'] == 'move') {
                $sourcePage->$pageTableFieldName->remove($item);
                $sourcePage->save($pageTableField);
            }
        }


        $this->successMessage = 'The contents of the ' . $pageTableFieldName . ' field were successfully ' . ($options['copyMove'] == 'move' ? 'moved' : 'copied') . ' from the ' . $sourcePage->path . ' page to the ' . $destinationPage->path . ' page.';
        return true;

    }

}