<?php

class PageManipulator extends ProcessAdminActions {

    protected $description = 'Uses an InputfieldSelector to query pages and then allows batch actions on the matched pages.';
    protected $notes = 'Actions are: Trash, Delete, Publish, Unpublish, Hide, Unhide.';
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
                'name' => 'action',
                'label' => 'Action',
                'description' => 'Choose the action to be executed',
                'type' => 'select',
                'options' => array(
                    'trash' => 'Trash',
                    'delete' => 'Delete',
                    'publish' => 'Publish',
                    'unpublish' => 'Unpublish',
                    'hide' => 'Hide',
                    'unhide' => 'Unhide'
                ),
                'required' => true
            )
        );
    }


    protected function executeAction($options) {

        $count = 0;
        foreach($this->pages->find($options['selector']) as $p) {
            $p->of(false);
            if($options['action'] == 'trash') $p->trash();
            if($options['action'] == 'delete') $p->delete();
            if($options['action'] == 'publish') $p->removeStatus(Page::statusUnpublished);
            if($options['action'] == 'unpublish') $p->addStatus(Page::statusUnpublished);
            if($options['action'] == 'hide') $p->addStatus(Page::statusHidden);
            if($options['action'] == 'unhide') $p->removeStatus(Page::statusHidden);
            $p->save();
            $count++;
        }

        $this->successMessage = 'The ' . $options['action'] . ' action was successfully applied to ' . $count .' page' . _n('', 's', $count) . '.';
        return true;

    }

}