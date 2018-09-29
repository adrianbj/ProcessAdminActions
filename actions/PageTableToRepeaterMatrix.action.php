<?php

class PageTableToRepeaterMatrix extends ProcessAdminActions {

    protected $title = 'Page Table to Repeater / Repeater Matrix';
    protected $description = 'Fully converts an existing (and populated) PageTable field to either a Repeater or RepeaterMatrix field.';
    protected $notes = 'By default it will choose Repeater or RepeaterMatrix based on the number of templates in the PageTable field, but you can also choose to force RepeaterMatrix.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Convert Field';
    protected $icon = 'exchange';

    protected function checkRequirements() {
        if(!$this->wire('modules')->isInstalled("FieldtypeRepeater") && !$this->wire('modules')->isInstallable("FieldtypeRepeater", true)) {
            $this->requirementsMessage = 'The Repeater field type is not currently installable.';
            return false;
        }
        else {
            return true;
        }
    }

    protected function defineOptions() {
        return array(
            array(
                'name' => 'pageTableField',
                'label' => 'PageTable Field',
                'description' => 'Choose the PageTable field to be converted to a Repeater or RepeaterMatrix field.',
                'type' => 'select',
                'required' => true,
                'options' => $this->wire('fields')->find("type=FieldtypePageTable")->getArray()
            ),
            array(
                'name' => 'forceRepeaterMatrix',
                'label' => 'Force RepeaterMatrix',
                'description' => 'Force RepeaterMatrix (rather than Repeater) even if there is only one template in the PageTable field that is being converted.',
                'notes' => 'This could be useful if you are planning on having multiple repeater template types for this field in the future.',
                'type' => 'radios',
                'required' => true,
                'options' => array(
                    0 => 'False',
                    1 => 'True'
                ),
                'optionColumns' => 1,
                'value' => 0,
            ),
            array(
                'name' => 'deleteOldTemplates',
                'label' => 'Delete Old Templates',
                'description' => 'Delete the template(s) used within the old PageTable field. They will only be deleted if they are not used by any other pages.',
                'notes' => 'Recommended',
                'type' => 'radios',
                'required' => true,
                'options' => array(
                    0 => 'False',
                    1 => 'True'
                ),
                'optionColumns' => 1,
                'value' => 1,
            ),
            array(
                'name' => 'emptyTrash',
                'label' => 'Empty Trash',
                'description' => 'Empty trash before starting to ensure no trashed pages are using templates we are trying to delete.',
                'notes' => 'Recommended',
                'type' => 'radios',
                'required' => true,
                'options' => array(
                    0 => 'False',
                    1 => 'True'
                ),
                'optionColumns' => 1,
                'value' => 1,
            )
        );
    }


    protected function executeAction($options) {

        $fields = $this->wire('fields');
        $pages = $this->wire('pages');
        $modules = $this->wire('modules');
        $templates = $this->wire('templates');
        $fieldgroups = $this->wire('fieldgroups');
        $languages = $this->wire('languages');

        // the Page Table field to be converted to a Repeater field
        $pageTableField = $fields->get((int)$options['pageTableField']);
        $pageTableFieldName = $pageTableFieldOriginalName = $pageTableField->name;

        // force Repeater Matrix even if there is only only template in the Page Table field that is being converted
        $forceRepeaterMatrix = $this->wire('sanitizer')->bool($options['forceRepeaterMatrix']);

        // delete the template(s) used within the old PageTable field if they are not used by any other pages?
        $deleteOldTemplates = $this->wire('sanitizer')->bool($options['deleteOldTemplates']);

        // empty trash before starting to ensure no trashed pages are using templates we are trying to delete
        $emptyTrash = $this->wire('sanitizer')->bool($options['emptyTrash']);


        if(!$pageTableField) {
            $this->wire()->error('Sorry, the ' . $pageTableFieldName . ' Page Table field does not exist');
            return false;
        }
        else {
            $repeaterFieldName = $pageTableFieldName;
            $pageTableTemplateIds = $fields->get($pageTableFieldName)->template_id;

            if(count($pageTableTemplateIds) > 1 || $forceRepeaterMatrix) {
                if($modules->get('FieldtypeRepeaterMatrix')) {
                    $repeaterType = 'FieldtypeRepeaterMatrix';
                }
                else {
                    $this->wire()->error('Sorry, the Repeater Matrix field type is not available.');
                    return false;
                }
            }
            else {
                $repeaterType = 'FieldtypeRepeater';
            }

            //if allowed, empty the trash now
            if($emptyTrash) $pages->emptyTrash();

            //rename PageTable field
            $pageTableField->name = $newPageTableFieldName = $pageTableFieldName. "_oldpagetablefield";
            $pageTableField->save();

            //for Repeater Matrix because more than one template was defined for Page Table field
            if($repeaterType == 'FieldtypeRepeaterMatrix') {
                $allFieldIds = array();
                $matrixFieldIds = array();
                $matrixId = 1;
                foreach($pageTableTemplateIds as $template_id) {
                    foreach($templates->get($template_id)->fields as $field) {
                        $allFieldIds[] = $field->id;
                        $matrixFieldIds[$matrixId]['fieldIds'][] = $field->id;
                    }
                    $matrixFieldIds[$matrixId]['templateId'] = $template_id;
                    $matrixFieldIds[$matrixId]['templateName'] = $templates->get($template_id)->name;
                    $matrixFieldIds[$matrixId]['templateLabel'] = $templates->get($template_id)->label != '' ? $templates->get($template_id)->label : $templates->get($template_id)->name;
                    $matrixId++;
                }

                //create repeater fieldgroup
                $repeaterFieldgroup = new Fieldgroup();
                $repeaterFieldgroup->name = "repeater_$repeaterFieldName";

                //add special repeater_matrix_type field to the template
                $repeater_matrix_field = $fields->get("repeater_matrix_type");
                $repeaterFieldgroup->add($repeater_matrix_field);

                //add fields to fieldgroup
                foreach($allFieldIds as $fieldId) {
                    $repeaterFieldgroup->append($fields->get($fieldId));
                }

                $repeaterFieldgroup->save();

                //create repeater template for this field
                $repeaterTemplate = new Template();
                $repeaterTemplate->name = "repeater_$repeaterFieldName";
                $repeaterTemplate->flags = 8;
                $repeaterTemplate->noChildren = 1;
                $repeaterTemplate->noParents = 1;
                $repeaterTemplate->noGlobal = 1;
                $repeaterTemplate->slashUrls = 1;
                $repeaterTemplate->fieldgroup = $repeaterFieldgroup;
                $repeaterTemplate->save();

            }
            //for standard Repeater because only one template was defined for Page Table field
            else {
                $pageTableTemplate = $templates->get($pageTableTemplateIds[0]);
                $repeaterTemplate = $templates->clone($pageTableTemplate, "repeater_".$pageTableTemplate->name);

                //reconfigure cloned repeater template
                $repeaterTemplate->flags = 8;
                $repeaterTemplate->noChildren = 1;
                $repeaterTemplate->noParents = 1;
                $repeaterTemplate->noGlobal = 1;
                $repeaterTemplate->save();
            }

            //create repeater field
            $repeaterField = new Field();
            $repeaterField->type = $modules->get($repeaterType);
            $repeaterField->template_id = $repeaterTemplate->id;
            $repeaterField->name = $repeaterFieldName;
            $repeaterField->label = $pageTableField->label;
            $repeaterField->description = $pageTableField->description;
            $repeaterField->notes = $pageTableField->notes;
            if($modules->isInstalled("LanguageSupport")) {
                foreach($languages as $language) {
                    $repeaterField->set("label$language", $pageTableField->{"label$language"});
                    $repeaterField->set("description$language", $pageTableField->{"description$language"});
                    $repeaterField->set("notes$language", $pageTableField->{"notes$language"});
                }
            }
            $repeaterField->flags = $pageTableField->flags;
            $repeaterField->collapsed = $pageTableField->collapsed;
            $repeaterField->tags = $pageTableField->tags;
            $repeaterField->showIf = $pageTableField->showIf;
            $repeaterField->columnWidth = $pageTableField->columnWidth;
            $repeaterField->required = $pageTableField->required;
            $repeaterField->requiredIf = $pageTableField->requiredIf;

            //add the subfields to the repeaterFields setting
            if($repeaterType == 'FieldtypeRepeaterMatrix') {
                //add fields from each template to the appropriate matrix"n"_fields object
                foreach($matrixFieldIds as $id => $template) {
                    $repeaterField->{'matrix'.$id.'_name'} = $template['templateName'];
                    $repeaterField->{'matrix'.$id.'_label'} = $template['templateLabel'];
                    $repeaterField->{'matrix'.$id.'_head'} = "{matrix_label} [• {matrix_summary}]";
                    $repeaterField->{'matrix'.$id.'_fields'} = $template['fieldIds'];
                }
            }
            else {
                //if Page Table template has a title field, then set the repeater items label to the title field
                if($pageTableTemplate->hasField('title')) $repeaterField->repeaterTitle = '{title}';
            }

            //add fields to repeaterFields
            $repeaterFieldsArray = array();
            foreach($repeaterTemplate->fields as $field) {
                $repeaterFieldsArray[] = $field->id;
            }
            $repeaterField->repeaterFields = $repeaterFieldsArray;

            $repeaterField->save();

            //setup page for the repeater and assign to repeater field
            $repeaterPageName = "for-field-{$repeaterField->id}";
            $repeaterFieldParent = new Page();
            $repeaterFieldParent->name = $repeaterPageName;
            $repeaterFieldParent->parent = $pages->get("template=admin, name=repeaters");
            $repeaterFieldParent->template = "admin";
            $repeaterFieldParent->title = $repeaterField->name;
            $repeaterFieldParent->save();

            //add newly created parent page as parent_id to repeater field
            $repeaterField->parent_id = $repeaterFieldParent->id;
            $repeaterField->save();

            //get all pages that contain the Page Table field
            $pagesToEdit = new PageArray();
            foreach($templates as $t) {
                if($t->hasField($pageTableField)) {
                    foreach($pages->find("template={$t->name}, include=all") as $p) {
                        $pagesToEdit->add($p);
                    }
                }
            }

            //iterate through all pages that have the Page Table field
            $lastTemplateName = '';
            foreach($pagesToEdit as $p) {

                $p->of(false);

                //add repeater field to the template of the selected page
                if(!$p->template->hasField($repeaterField)) {
                    $p->template->fields->insertAfter($repeaterField, $pageTableField);
                    $p->template->fields->save();
                }

                //migrate template context settings from Page Table field to the new Repeater field
                if($lastTemplateName !== $p->template->name) {
                    foreach($p->template->fieldgroup->getFieldContextArray($pageTableField->id) as $field_setting => $value){
                        //get the field in context of this template
                        $f = $p->template->fieldgroup->getField($repeaterField->name, true);
                        $f->$field_setting = $value;
                        //save new setting in context
                        $fields->saveFieldgroupContext($f, $p->template->fieldgroup);
                    }
                }

                //manually create parent page for each main page which has content (if count > 0) for this repeater field
                //this is normally done automatically when creating repeater items, but because we are moving
                //existing Page Table pages, we need to do this manually
                if($p->$newPageTableFieldName->count() > 0) {
                    $repeaterPageParent = new Page();
                    $repeaterPageParent->name = "for-page-{$p->id}";
                    $repeaterPageParent->parent = $repeaterFieldParent;
                    $repeaterPageParent->template = "admin";
                    $repeaterPageParent->title = $p->name;
                    $repeaterPageParent->save();

                    //move item pages from old Page Table parent to the new repeater page parent
                    foreach($p->$newPageTableFieldName->find("sort=sort") as $item){
                        $item->of(false);
                        $item->parent = $repeaterPageParent;
                        $item->name = $this->getUniqueRepeaterPageName();
                        if($repeaterType == 'FieldtypeRepeaterMatrix') {
                            $itemTemplateId = $item->template_id;
                            foreach($matrixFieldIds as $id => $template) {
                                if($template['templateId'] === $itemTemplateId) {
                                    $item->repeater_matrix_type = $id;
                                    break;
                                }
                            }
                        }
                        $item->template = $repeaterTemplate;
                        //repeater items seem to need StatusOn (1) not set when unpublished
                        //TODO - this might need to be revised as it seems like a hack
                        $item->status = $item->status > 1 ? $item->status-1 : $item->status;
                        $item->save();
                        $p->$repeaterFieldName->add($item);
                    }
                    //save the repeater field on the main page
                    $p->save($repeaterFieldName);
                }

                $lastTemplateName = $p->template->name;
            }

            //second iteration to delete Page Table field from each page's template
            foreach($pagesToEdit as $p) {
                if($p->template->hasField($pageTableField)) {
                    $p->template->fieldgroup->remove($pageTableField);
                    $p->template->fieldgroup->save();
                }
            }

            //delete the Page Table field
            $fields->delete($pageTableField);

            //if allowed and no pages using it, delete the old Page Table Template(s)
            if($deleteOldTemplates) {
                if($repeaterType == 'FieldtypeRepeaterMatrix') {
                    foreach($pageTableTemplateIds as $template_id) {
                        if($pages->count("include=all, template=".$template_id) === 0) {
                            $pageTableTemplate = $templates->get($template_id);
                            $pageTableFieldgroup = $pageTableTemplate->fieldgroup;
                            $templates->delete($pageTableTemplate);
                            $fieldgroups->delete($pageTableFieldgroup);
                        }
                    }
                }
                else {
                    if($pages->count("include=all, template=".$pageTableTemplate) === 0) {
                        $pageTableFieldgroup = $pageTableTemplate->fieldgroup;
                        $templates->delete($pageTableTemplate);
                        $fieldgroups->delete($pageTableFieldgroup);
                    }
                }
            }

            $this->successMessage = 'The "' . $pageTableFieldOriginalName . '" field was successfully converted to a ' . str_replace('Fieldtype', '', $repeaterType) . ' field.';
            return true;

        }

    }

    private function getUniqueRepeaterPageName() {
        static $cnt = 0;
        return str_replace('.', '-', microtime(true)) . '-' . (++$cnt);
    }

}