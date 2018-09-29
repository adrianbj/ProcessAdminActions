<?php

class PageManipulator extends ProcessAdminActions {

    protected $title = 'Page Manipulator';
    protected $description = 'Uses an InputfieldSelector to query pages and then allows batch actions on the matched pages.';
    protected $notes = 'Actions are: Publish, Unpublish, Hide, Unhide, Trash, Delete, Change Template, Change Parent';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Apply Changes';
    protected $icon = 'cog';

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
                'type' => 'radios',
                'optionColumns' => 1,
                'options' => array(
                    'nochange' => 'No Change',
                    'trash' => 'Trash',
                    'delete' => 'Delete',
                    'deleteIncludeChildren' => 'Delete (include children)'
                ),
                'required' => true,
                'value' => 'nochange'
            ),
            array(
                'name' => 'hidden',
                'label' => 'Hidden',
                'type' => 'radios',
                'optionColumns' => 1,
                'options' => array(
                    'nochange' => 'No Change',
                    'hide' => 'Hide',
                    'unhide' => 'Unhide'
                ),
                'required' => true,
                'value' => 'nochange'
            ),
            array(
                'name' => 'published',
                'label' => 'Published',
                'type' => 'radios',
                'optionColumns' => 1,
                'options' => array(
                    'nochange' => 'No Change',
                    'publish' => 'Publish',
                    'unpublish' => 'Unpublish'
                ),
                'required' => true,
                'value' => 'nochange'
            ),
            array(
                'name' => 'locked',
                'label' => 'Locked',
                'type' => 'radios',
                'optionColumns' => 1,
                'options' => array(
                    'nochange' => 'No Change',
                    'locked' => 'Locked',
                    'unlocked' => 'Unlocked'
                ),
                'required' => true,
                'value' => 'nochange'
            ),
            array(
                'name' => 'changeParent',
                'label' => 'Change Parent',
                'type' => 'pageListSelect',
            ),
            array(
                'name' => 'changeTemplate',
                'label' => 'Change Template',
                'type' => 'select',
                'options' => $this->wire('templates')->find("sort=name, flags!=".Template::flagSystem)->getArray()
            )
        );
    }


    protected function executeAction($options) {

        $count = 0;
        foreach($this->wire('pages')->find($options['selector']) as $p) {
            $p->of(false);

            if($options['remove'] === 'trash') $p->trash();
            if($options['remove'] === 'delete') $p->delete();
            if($options['remove'] === 'deleteIncludeChildren') $this->wire('pages')->delete($p, true);

            if($options['hidden'] === 'hide') $p->addStatus(Page::statusHidden);
            if($options['hidden'] === 'unhide') $p->removeStatus(Page::statusHidden);
            if($options['published'] === 'publish') $p->removeStatus(Page::statusUnpublished);
            if($options['published'] === 'unpublish') $p->addStatus(Page::statusUnpublished);
            if($options['locked'] === 'locked') $p->addStatus(Page::statusLocked);
            if($options['locked'] === 'unlocked') $p->removeStatus(Page::statusLocked);

            if($options['changeParent']) $p->parent = (int)$options['changeParent'];
            if($options['changeTemplate']) $p->template = (int)$options['changeTemplate'];

            $p->save();
            $count++;
        }

        $this->successMessage = 'The actions were successfully applied to ' . $count .' page' . _n('', 's', $count) . '.';
        return true;

    }

}