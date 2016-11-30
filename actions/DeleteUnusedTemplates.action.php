<?php

class DeleteUnusedTemplates extends ProcessAdminActions {

    protected $description = 'Deletes templates that are not used by any pages.';
    protected $notes = 'Shows a list of unused templates with checkboxes to select those to delete.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected function defineOptions() {

        $templateOptions = array();
        foreach($this->templates as $template) if(!$template->getNumPages()) $templateOptions[$template->id] = $template->label ? $template->name . ' (' . $template->label . ')' : $template->name;

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

        $count = 0;
        foreach($options['templates'] as $template) {
            $t = $this->templates->get($template);
            $this->templates->delete($t);
            $name = $t->name;
            $fg = $this->fieldgroups->get($name);
            $this->fieldgroups->delete($fg);
            $count++;
        }

        $this->successMessage = $count . ' template' . _n('', 's', $count) . ' ' . _n('was', 'were', $count) . ' successfully deleted';
        return true;

    }

}