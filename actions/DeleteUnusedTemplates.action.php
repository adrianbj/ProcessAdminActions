<?php

class DeleteUnusedTemplates extends ProcessAdminActions {

    protected $title = 'Delete Unused Templates';
    protected $description = 'Deletes templates that are not used by any pages.';
    protected $notes = 'Shows a list of unused templates with checkboxes to select those to delete.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Delete Checked Templates';
    protected $icon = 'minus-circle';

    protected function defineOptions() {

        $templateOptions = array();
        foreach($this->wire('templates') as $template) if(!$template->getNumPages()) $templateOptions[$template->id] = $template->label ? $template->name . ' (' . $template->label . ')' : $template->name;

        return array(
            array(
                'name' => 'templates',
                'label' => 'Templates',
                'description' => 'Select the templates you want to delete',
                'notes' => 'Note that all templates listed are not used by any pages and should therefore be safe to delete',
                'type' => 'checkboxes',
                'options' => $templateOptions,
                'required' => true
            )
        );

    }


    protected function executeAction($options) {

        foreach($options['templates'] as $template_id) {
            $template = $this->wire('templates')->get((int)$template_id);
            $this->wire('templates')->delete($template);
            $templateName = $template->name;
            $fieldgroup = $this->wire('fieldgroups')->get($templateName);
            $this->wire('fieldgroups')->delete($fieldgroup);
        }
        $count = count($options['templates']);
        $this->successMessage = $count . ' template' . _n('', 's', $count) . ' ' . _n('was', 'were', $count) . ' successfully deleted';
        return true;

    }

}