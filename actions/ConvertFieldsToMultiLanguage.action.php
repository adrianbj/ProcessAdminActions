<?php

class ConvertFieldsToMultiLanguage extends ProcessAdminActions {

    protected $title = 'Convert Fields to Multi-Language';
    protected $description = 'Converts fields to their matching multi-language fieldtype.';
    protected $notes = 'Shows a list of potential fields that can be selected for conversion.';
    protected $author = 'Philipp Daun';
    protected $authorLinks = array(
        'pwforum' => '3989-dhinnisda%C3%ABl',
        'pwdirectory' => 'philippdaun',
        'github' => 'daun',
    );

    protected $executeButtonLabel = 'Convert Selected Fields';
    protected $icon = 'language';

    /**
     * Fieldtypes we'll try to convert to multi-language variants of themselves.
     *
     * Note that some of these don't exist as multi-lang yet, but it's plausible
     * that might in the near future.
     *
     * @var array
     */
    protected $convertableFieldtypes = [
        'FieldtypePageTitle',
        'FieldtypeText',
        'FieldtypeTextarea',
        'FieldtypeURL',
        'FieldtypeCheckbox',
        'FieldtypeEmail',
    ];

    protected function defineOptions() {
        $labels = (object) [
            'system' => $this->_('System'),
            'permanent' => $this->_('Permanent'),
        ];

        $fieldsByType = [];
        foreach($this->wire('fields') as $field) {
            $newType = $this->getFieldtypeLanguageVersion($field);
            if (!$newType) continue;

            $type = "{$field->type}";
            $label = $field->label ? "{$field->name} ({$field->label})" : $field->name;

            if ($field->flags & Field::flagSystem) $label = "{$label} ⚠ {$labels->system}";
            if ($field->flags & Field::flagPermanent) $label = "{$label} ⚠ {$labels->permanent}";

            $fieldsByType[$type] = $fieldsByType[$type] ?? [];
            $fieldsByType[$type][$field->id] = $label;
        }

        $optionFields = [
            [
                'name' => "keepSettings",
                'label' => "Keep field settings?",
                'description' => "Check this box to retain any custom field settings (from the Details and Input tabs). This is desirable for similar field types, but it can also result in unnecessary or redundant configuration data taking up space in the field. You can always analyze this later from: Edit Field > Advanced > Check field data.",
                'type' => 'checkbox',
                'checked' => 1,
            ]
        ];

        ksort($fieldsByType);
        $typeNum = count($fieldsByType);
        $fieldsByTypeKeys = array_keys($fieldsByType);
        $lastType = array_pop($fieldsByTypeKeys);

        foreach ($fieldsByType as $type => $options) {
            $newType = $this->getFieldtypeLanguageVersion($type);
            $even = $typeNum % 2 === 0;
            $last = $type == $lastType;
            $optionFields[] = [
                'name' => "fields__{$type}",
                'label' => "Fields: {$type}",
                'description' => "Select the fields to convert to **{$newType}**.",
                'columnWidth' => ($even || !$last) ? 50 : 100,
                'type' => 'checkboxes',
                'options' => $options,
            ];
        }

        return $optionFields;
    }

    protected function executeAction($options) {
        $count = 0;

        $typeOptions = array_filter($options, function ($key) {
            return strpos($key, 'fields__') === 0;
        }, ARRAY_FILTER_USE_KEY);

        $keepSettings = $options['keepSettings'] ?? true;

        foreach ($typeOptions as $type => $fields) {
            $type = str_replace('fields__', '', $type);
            foreach ($fields as $fieldId) {
                $field = $this->wire('fields')->get((int) $fieldId);
                $newType = $this->getFieldtypeLanguageVersion($field);
                $this->message("Converting field from {$field->type} to {$newType}: {$field->name}");
                try {
                    $field->prevFieldtype = $field->type;
                    $field->type = $newType;
                    if ($this->wire('fields')->changeFieldtype($field, $keepSettings)) {
                        $field->prevFieldtype = null;
                        $field->save();
                        $count++;
                    } else {
                        $field->type = $field->prevFieldtype;
                        $this->error("Error changing fieldtype for '$field', reverted back to '{$field->type}'");
                    }
                } catch (\Throwable $th) {
                    $this->error("Error changing fieldtype for '$field': {$th->getMessage()}");
                }
            }
        }

        $wordFields = $this->_n('field', 'fields', $count);
        $wordWere = $this->_n('was', 'were', $count);

        if ($count) {
            $this->successMessage = "{$count} {$wordFields} {$wordWere} successfully converted";
            return true;
        } else {
            $this->failureMessage = "No fieldtypes converted";
            return false;
        }
    }

    protected function getFieldtypeLanguageVersion($current) {
        if (is_object($current)) $current = $current->type;
        if (in_array($current, $this->convertableFieldtypes)) {
            $newType = $this->wire('fieldtypes')->get("{$current}Language");
            return $newType ?: false;
        }
        return false;
    }
}
