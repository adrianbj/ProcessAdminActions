<?php

class PageManipulator extends ProcessAdminActions {

    protected $description = 'Uses an InputfieldSelector to query pages and then allows batch actions on the matched pages.';
    protected $notes = 'Actions are: Publish, Unpublish, Hide, Unhide, Trash, Delete, Change Template, Change Parent';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

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
                'name' => 'remove',
                'label' => 'Trash or Delete',
                'type' => 'checkboxes',
                'options' => array(
                    'trash' => 'Trash',
                    'delete' => 'Delete'
                )
            ),
            array(
                'name' => 'status',
                'label' => 'Status',
                'type' => 'checkboxes',
                'showIf'=> 'remove.count=0',
                'options' => array(
                    'publish' => 'Publish',
                    'unpublish' => 'Unpublish',
                    'hide' => 'Hide',
                    'unhide' => 'Unhide'
                )
            ),
            array(
                'name' => 'changeParent',
                'label' => 'Change Parent',
                'type' => 'pageListSelect',
                'showIf'=> 'remove.count=0',
            ),
            array(
                'name' => 'changeTemplate',
                'label' => 'Change Template',
                'type' => 'select',
                'showIf'=> 'remove.count=0',
                'options' => $this->templates->find("sort=name, flags!=".Template::flagSystem)->getArray()
            )
        );
    }


    protected function executeAction($options) {

        $count = 0;
        foreach($this->pages->find($options['selector']) as $p) {
            $p->of(false);

            if(in_array('trash', $options['remove'])) $p->trash();
            if(in_array('delete', $options['remove'])) $p->delete();

            if(in_array('publish', $options['status'])) $p->removeStatus(Page::statusUnpublished);
            if(in_array('unpublish', $options['status'])) $p->addStatus(Page::statusUnpublished);
            if(in_array('hide', $options['status'])) $p->addStatus(Page::statusHidden);
            if(in_array('unhide', $options['status'])) $p->removeStatus(Page::statusHidden);

            if($options['changeParent']) $p->parent = $options['changeParent'];
            if($options['changeTemplate']) $p->template = $options['changeTemplate'];

            $p->save();
            $count++;
        }

        $this->successMessage = 'The actions were successfully applied to ' . $count .' page' . _n('', 's', $count) . '.';
        return true;

    }

}