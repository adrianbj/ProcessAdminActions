<?php

/**
 * ProcessWire Admin Actions.
 * by Adrian Jones
 *
 * Copyright (C) 2020 by Adrian Jones
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 */

class ProcessAdminActions extends Process implements Module, ConfigurableModule {

    public static function getModuleInfo() {
        return array(
            'title' => 'Admin Actions',
            'summary' => 'Control panel for running various admin actions',
            'author' => 'Adrian Jones',
            'version' => '0.8.7',
            'singular' => true,
            'autoload' => false,
            'icon'     => 'wrench',
            'useNavJSON' => true,
            'page' => array(
                'name' => 'admin-actions',
                'parent' => 'setup',
                'title' => 'Admin Actions'
            ),
            'permission' => 'page-view',
            'permissionMethod' => 'permissionCheck',
            'permissions' => array(
                'admin-actions' => 'Run selected AdminActions actions',
                'admin-actions-restore' => 'Run AdminActions restore feature'
            )
        );
    }


    /**
     * Data as used by the get/set functions
     *
     */
    protected $data = array();
    protected $action;
    protected $actions = array();
    protected $actionTypes = array();
    protected $hasPermission = false;
    protected $adminActionsCacheDir;
    protected $dbBackupFilename;
    protected $dbBackup;
    protected $noPermissionMessage = 'Sorry, you do not have permission to use this action.';
    protected $processPageUrl;


    /**
     * Populate the default config data
     *
     */
    public function __construct() {
        foreach(self::getDefaultData() as $key => $value) {
            $this->$key = $value;
        }

        $this->adminActionsCacheDir = $this->wire('config')->paths->cache . 'AdminActions/';
        $this->dbBackupFilename = 'AdminActionsBackup.sql';

        $id = $this->wire('modules')->getModuleID("ProcessAdminActions");
        $this->processPageUrl = $this->wire('pages')->get("process=$id")->url;
    }


    public function ___executeNavJSON(array $options = array()) {
        $options['items'] = array();
        $i=0;
        if($this->wire('user')->isSuperuser() || $this->wire('user')->hasPermission('admin-actions-restore')) {
            $options['items'][$i]['id'] = '#tab_restore';
            $options['items'][$i]['label'] = 'Restore';
            $options['items'][$i]['icon'] = 'reply';
            $i++;
        }
        foreach($this->data as $actionType => $actions) {
            if(is_array($actions)) {
                foreach($actions as $action => $info) {
                    if(isset($info['menu']) && $info['menu'] == '1' && isset($info['roles']) && count(array_intersect($info['roles'], $this->wire('user')->roles->each("id"))) !== 0) {
                        $options['items'][$i]['id'] = 'options?action='.$action;
                        $options['items'][$i]['label'] = $this->getActionTitle($action, $info);
                        $options['items'][$i]['icon'] = isset($info['icon']) ? $info['icon'] : '';
                        $i++;
                    }
                }
            }
        }
        $options['itemLabel'] = 'label';
        $options['edit'] = $this->processPageUrl . '{id}';
        $options['add'] = null;
        $options['iconKey'] = 'icon';
        $options['sort'] = false;
        return parent::___executeNavJSON($options);
    }


    public static function permissionCheck(array $data) {
        $user = $data['user'];
        $wire = $data['wire'];
        // if in admin/backend, then require "admin-actions" permission
        if(strpos($wire->input->url, $wire->config->urls->admin) === 0 && !$user->hasPermission('admin-actions')) {
            return false;
        }
        // else in frontend, so allow full access to call actions via the API
        else {
            return true;
        }
    }


   /**
     * Default configuration for module
     *
     */
    static public function getDefaultData() {
            return array(
                // intentionally blank
                // this method is required by ProcessWire, but we don't use it for this module
                // because defaults are saved as multidimensional JSON on install
            );
    }


    public function init() {
        parent::init();
        $this->wire('modules')->get("JqueryWireTabs");

        $this->getActionTypes();

        if($this->wire('input')->get->action) {
            if(isset($this->actionTypes[$this->wire('input')->get->action]) && count(array_intersect($this->data[$this->actionTypes[$this->wire('input')->get->action]][$this->wire('input')->get->action]['roles'], $this->wire('user')->roles->each("id"))) !== 0) {
                require_once $this->getActionPath($this->wire('input')->get->action);
                $actionName = $this->wire('input')->get->action;
                $this->action = new $actionName;
                $this->hasPermission = true;
                $this->addHookAfter('Process::breadcrumb', $this, 'modifyBreadcrumb');
            }
            else {
                return $this->noPermissionMessage;
            }
        }

        $this->dbBackup = isset($this->action->dbBackup) ? $this->action->dbBackup : (isset($this->data['dbBackup']) ? $this->data['dbBackup'] : 'automatic');
    }


    /**
     * Executed when root url for module is accessed
     *
     */
    public function ___execute() {

        $form = $this->buildSelectForm();

        $links = '';
        if($this->wire('user')->isSuperuser()) {
            $links = '<div id="links">';
            $links .= "<a href='" . $this->wire('config')->urls->admin . "module/edit?name=".$this."&collapse_info=1'><i class='fa fa-cog'></i> Settings</a>&nbsp;&nbsp;";
            $links .= "<a href='https://processwire.com/talk/topic/14921-admin-actions/' target='_blank'><i class='fa fa-comments'></i> Support</a>";
            $links .= '</div>';
        }

        if($this->wire('input')->post->submit) {
            return $links . $this->processSelectForm($form);
        }
        else {
            return $links . (is_string($form) ? $form : $form->render());
        }
    }

    /**
     * Executed when ./options/ url for module is accessed
     *
     */
    public function ___executeOptions() {

        if(!$this->hasPermission) return $this->noPermissionMessage;

        $out = '<h2>'.$this->getActionTitle($this->wire('input')->get->action, $this->data[$this->actionTypes[$this->wire('input')->get->action]][$this->wire('input')->get->action]).'</h2>';

        if(method_exists($this->action,'checkRequirements') && !$this->action->checkRequirements()) {
            $out .= '<div class="adminActionsError"><p>Sorry, this action cannot proceed. ' . $this->action->requirementsMessage . '</p></div>';
        }
        else {
            $form = $this->buildOptionsForm();
            if(!$this->wire('input')->post->submit) {
                $out .= is_string($form) ? $form : $form->render();
            }
        }
        return $out;
    }

    /**
     * Executed when ./run/ url for module is accessed
     *
     */
    public function ___executeRun() {

        set_time_limit(0);

        if(!$this->hasPermission) return $this->noPermissionMessage;

        $actionTitle = $this->getActionTitle($this->wire('input')->get->action, $this->data[$this->actionTypes[$this->wire('input')->get->action]][$this->wire('input')->get->action]);

        $form = $this->buildOptionsForm();
        // build up $options array to pass to the action's executeAction method
        $options = array();
        // If the number of URL parameters (get) matches the number of required option fields in the form,
        // then we want to execute with the url parameters.
        // Note that the URL ($input->get) contains the additional "action", so start with $i=1
        $i=1;
        foreach($form->getAll() as $field) {
            if($field->hasClass('required')) $i++;
        }
        $numGetParams = count($this->wire('input')->get);
        if($numGetParams > 1 && $numGetParams === $i) {
            $urlFields = new WireInputData();
            foreach($this->wire('input')->get as $field => $value) {
                // exclude fields that don't exist in the form, like the "action" url parameter
                if(!is_object($form->get($field)) || !$form->get($field)->parent) continue;
                // if field expects an array (multiple) then we need to convert the URL parameter
                if($form->get($field)->multiple) {
                    if(strpos($value, '|') !== false) {
                        $value = explode('|', $value);
                    }
                    else {
                        $value = array($value);
                    }
                }
                $urlFields[$field] = $value;
            }
            // disable CSRF because we are using GET data
            $form->protectCSRF = false;
            $form->processInput($urlFields);
            $form->protectCSRF = true;
            foreach($form->getAll() as $field) {
                $value = $form->get($field->name)->value;
                $options[$field->name] = $value;
            }
        }
        elseif(count($this->wire('input')->post) === 0) {
            return '<h2>' . $actionTitle . '</h2><p>Some required form options are missing.</p>';
        }
        else {
            $form->processInput($this->wire('input')->post);
            foreach($form->getAll() as $field) {
                $options[$field->name] = $form->get($field->name)->value;
            }
        }

        // backup database before executing action
        if($this->dbBackup == 'automatic' || ($this->dbBackup == 'optional' && $form->get("dbBackup")->value && (bool)$form->get("dbBackup")->value === true)) {
            $this->backupDb();
            if($this->wire('user')->isSuperuser() || $this->wire('user')->hasPermission('admin-actions-restore')) {
                $restoreLink = '<br /><div class="adminActionsWarning"><p>If you find a problem with the changes, you can <a href="./#tab_restore">restore</a> the entire database.</p></div>';
            }
        }
        else {
            $restoreLink = '';
        }

        // if form errors, render the form without executing it
        if($form->getErrors()) {
            return '<h2>' . $actionTitle . '</h2>' . $form->render();
        }
        // execute action and render output
        elseif($this->action->executeAction($options) && !$form->getErrors()) {
            return '<h2>' . $actionTitle . '</h2>' . $this->action->output . '<div class="adminActionsSuccess">' . ($this->action->successMessage == '' ? '<p>The '.$actionTitle.' action was completed successfully.</p>' : '<p>' . $this->action->successMessage . '</p>') . '</div>' . $restoreLink;
        }
        // execution errors so return the failure message and render the form again
        else {
            $this->wire()->error('Sorry, the '.$actionTitle.' action could not be completed successfully.');
            return '<h2>' . $actionTitle . '</h2>' . ($this->action->failureMessage ? '<div class="adminActionsError"><p>' . $this->action->failureMessage . '</p></div>' : '') . $restoreLink . '<br /><br />' . $form->render();
        }

    }

    /**
     * Executed when legacy ./execute/ url for module is accessed
     *
     */
    public function ___executeExecute() {

        // "execute" is not in use anymore: renamed to "run" to prevent firewall blocking
        // we'll pass through calls to the new method for legacy installs with possibly hardcoded urls
        return $this->___executeRun();

    }

    /**
     * Build the Select form
     *
     */
    private function buildSelectForm() {

        $this->wire('session')->warnings('clear');

        $form = $this->wire('modules')->get("InputfieldForm");
        $form->name = 'select';
        $form->method = 'post';

        $tabsWrapper = new InputfieldWrapper();
        $tabsWrapper->attr("id", "AdminActionsList");

        // get all actions and check to see if there are any new ones not in the module settings database
        $this->getAllActions(false);
        if($this->wire('user')->isSuperuser() && $this->newActionsAvailable()) {
            $this->wire('session')->redirect($this->wire('config')->urls->admin . "module/edit?name=" . $this . "&install=1");
        }
        elseif($this->wire('input')->get->installed) {
            $this->wire()->warning($this->wire('input')->get->installed . " new " . _n('action was', 'actions were', $this->wire('input')->get->installed) . " installed. If you need to adjust role access and menu status, visit please visit the <a href='".$this->wire('config')->urls->admin . 'module/edit?name=' . $this . "'>module config settings</a>.", Notice::allowMarkup);
        }

        if(isset($this->data['site'])) ksort($this->data['site']);
        ksort($this->data['core']);

        $actionsCount=0;
        foreach($this->data as $actionType => $actionsList) {

            if(!is_array($actionsList)) continue;

            // if the user has no actions in this list (site or core) with permission for their role, then don't display the tab
            $hasTypePermission = false;
            foreach($actionsList as $action => $info) {
                if($this->getActionPath($action) && isset($info['roles']) && count(array_intersect($info['roles'], $this->wire('user')->roles->each("id"))) !== 0) {
                    $hasTypePermission = true;
                }
            }
            if(!$hasTypePermission) continue;

            $tab = new InputfieldWrapper();
            $tab->attr('id', 'tab_'.$actionType.'_actions');
            $tab->attr('title', ucfirst($actionType));
            $tab->attr('class', 'WireTab');

            $f = $this->wire('modules')->get("InputfieldMarkup");
            $f->attr('name', 'table');
            $table = $this->wire('modules')->get("MarkupAdminDataTable");
            $table->setEncodeEntities(false);
            $table->setSortable(true);
            $table->setClass('adminactions');
            $table->headerRow(array(
                __('Title'),
                __('Description'),
                __('Notes')
            ));

            foreach($actionsList as $action => $info) {
                if($this->getActionPath($action) && isset($info['roles']) && count(array_intersect($info['roles'], $this->wire('user')->roles->each("id"))) !== 0) {
                    $row = array(
                        '<p><strong><a data-path="'.$this->getActionPath($action).'" href="./options?action='.$action.'">'.(isset($info['icon']) && $info['icon'] != '' ? '<i class="fa fa-'.$info['icon'].'"></i> ' : '').$this->getActionTitle($action, $info).'</a></strong></p>',
                        isset($info['description']) && $info['description'] != '' ? $info['description'] : '',
                        isset($info['notes']) && $info['notes'] != '' ? '<span class="notes">'.$info['notes'].'</span>' : '',
                    );
                    $table->row($row);
                    $actionsCount++;
                }
            }

            $f->attr('value', $table->render());

            $tab->add($f);
            $tabsWrapper->add($tab);
        }

        if(file_exists($this->adminActionsCacheDir.$this->dbBackupFilename) && ($this->wire('user')->isSuperuser() || $this->wire('user')->hasPermission('admin-actions-restore'))) {

            $tab = new InputfieldWrapper();
            $tab->attr('id', 'tab_restore');
            $tab->attr('title', 'Restore');
            $tab->attr('class', 'WireTab');

            if($this->wire('input')->post->restoreConfirm) {
                $backup = new WireDatabaseBackup($this->adminActionsCacheDir);
                $backup->setDatabase($this->wire('database'));
                $backup->setDatabaseConfig($this->wire('config'));

                $success = $backup->restore($this->adminActionsCacheDir.$this->dbBackupFilename, array('dropAll' => true));
                if($success) {
                    $this->wire()->message("Database successfully restored.");
                }
                else {
                    $this->wire()->error("Sorry, there was a problem and the database could not be restored.");
                }
                $this->wire('session')->redirect('./');
            }
            else {
                $restore = $this->wire('modules')->get("InputfieldForm");
                $restore->name = 'restore';
                $restore->label = 'Restore';
                $restore->method = 'post';

                $f = $this->wire('modules')->get("InputfieldSubmit");
                $f->attr("id+name", "restoreConfirm");
                $f->name = 'restoreConfirm';
                $f->description = 'Restore entire site database to state before the last executed action at '.date("Y-m-d H:i:s T", filemtime($this->adminActionsCacheDir.$this->dbBackupFilename));
                $f->notes = 'Warning, this may take a minute or two, and cannot be undone!';
                $f->value = 'Restore';
                $restore->add($f);

                $tab->add($restore);
                $tabsWrapper->add($tab);
            }
        }

        $form->add($tabsWrapper);


        if($actionsCount > 0) {
            return $form;
        }
        else {
            return 'Sorry, you do not have permission to run any actions.';
        }
    }

    /**
     * Process the action select form
     *
     */
    private function processSelectForm(InputfieldForm $form) {

        $form->processInput($this->wire('input')->post);
        if(count($form->getErrors())) {
            $this->wire()->error($form->getErrors());
            return $form->render();
        }
        else {
            $this->wire('session')->redirect('./options?action='.$form->get("action")->value);
        }

    }

    /**
     * Build the Options form
     *
     */
    private function buildOptionsForm() {

        $form = $this->wire('modules')->get("InputfieldForm");
        $form->name = 'options';
        $form->method = 'post';
        $form->action = './run?action='.$this->action;

        $f = $this->wire('modules')->get("InputfieldMarkup");
        $f->name = 'details';
        $f->label = 'Details';
        $f->description = $this->action->description;
        $f->notes = $this->action->notes;
        $form->add($f);

        $urlParts = explode('/', trim($this->wire('input')->url,'/'));
        if(isset($this->data['showActionCode']) && $this->data['showActionCode'] && $this->wire('user')->isSuperuser() && end($urlParts) === 'options') {
            $this->wire('config')->scripts->add($this->wire('config')->urls->$this . 'ace-editor/ace.js');
            $this->wire('config')->scripts->add($this->wire('config')->urls->$this . 'ace-setup.js');
            $f = $this->wire('modules')->get("InputfieldMarkup");
            $f->name = 'actionCode';
            $f->label = 'Action Code';
            $f->description = 'This is the executeAction() method used by this action. This is just here as a reference in case you are curious what code is about to be executed.';
            $f->notes = 'The $options array contains the index (key) and value for each of the options defined below.';
            $f->value = '<div id="actionCodeViewer">'.$this->getFunctionCode($this->getActionPath($this->wire('input')->get->action), 'executeAction').'</div>';
            $f->collapsed = Inputfield::collapsedYes;
            $form->add($f);
        }

        if(method_exists($this->action,'defineOptions')) $form->add($this->action->defineOptions());

        if($this->dbBackup == 'optional') {
            $f = $this->wire('modules')->get("InputfieldCheckbox");
            $f->name = 'dbBackup';
            $f->label = __('Database Backup');
            $f->description = __('Do you want to automatically backup the database before executing this action?');
            $f->notes = __('This is highly recommended!');
            $form->add($f);
        }

        $f = $this->wire('modules')->get("InputfieldSubmit");
        $f->name = 'submit';
        $f->value = $this->action->executeButtonLabel ?: __('Execute Action');
        $form->add($f);

        //preset any fields provided as URL parameters
        foreach($form->getAll() as $field) {
            if($fieldValue = $this->wire('input')->get($field->name)) {
                if (strpos($fieldValue, '|') !== false) $fieldValue = explode('|', $fieldValue);
                $form->get($field->name)->value = $fieldValue;
            }
        }

        return $form;
    }


    private function getActionPath($className) {
        if(file_exists($actionPath = __DIR__ . '/actions/'.$className.'.action.php')) {
            return $actionPath;
        }
        elseif(file_exists($actionPath = __DIR__ . '/actions/'.$className.'/'.$className.'.action.php')) {
            return $actionPath;
        }
        elseif(file_exists($actionPath = $this->wire('config')->paths->templates.'AdminActions/'.$className.'.action.php')) {
            return $actionPath;
        }
        elseif(file_exists($actionPath = $this->wire('config')->paths->templates.'AdminActions/'.$className.'/'.$className.'.action.php')) {
            return $actionPath;
        }
        elseif($this->wire('modules')->isInstalled('AdminActions'.$className)) {
            return $this->wire('config')->paths->siteModules . 'AdminActions' . $className.'/'.$className.'.action.php';
        }
        else {
            return false;
        }
    }


    private function getAllActions($instantiate = true) {
        // get site actions from the /site/templates/AdminActions folder
        if(file_exists($customActionsDir = $this->wire('config')->paths->templates.'AdminActions/')) {
            $this->getActions($customActionsDir, $instantiate);
        }
        // get site actions installed as PW modules
        foreach($this->wire('modules')->getRequiredBy($this) as $action) {
            $this->getActions($this->wire('config')->paths->siteModules . $action . '/', $instantiate);
        }
        // get core actions from this module's action subfolder
        $this->getActions(__DIR__ . '/actions/', $instantiate);
    }


    private function getActions($folderPath, $instantiate = true) {
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if($file->isDir()) continue;
            if(substr($file->getBasename(), 0, 1) == '.') continue;
            if($file->isFile()) {
                $actionType = $this->wire('config')->paths->$this.'actions/' == $folderPath ? 'core' : 'site';
                $basename = $file->getBasename();
                if(!strpos($basename, '.action')) continue;
                if(!preg_match('/^([A-Z][a-zA-Z0-9_]+)\.action\.php$/', $basename, $matches)) continue;
                $className = $matches[1];
                if($instantiate) {
                    require_once $this->getActionPath($className);
                    $action = new $className;
                    $this->actions[$actionType][$className]['title'] = $action->title ?: $this->getInfoFieldValues($className, 'title');
                    $this->actions[$actionType][$className]['description'] = $action->description ?: $this->getInfoFieldValues($className, 'summary');
                    $this->actions[$actionType][$className]['notes'] = $action->notes ?: $this->getInfoFieldValues($className, 'notes');
                    $this->actions[$actionType][$className]['icon'] = $action->icon ?: $this->getInfoFieldValues($className, 'icon');
                    $this->actions[$actionType][$className]['author'] = $action->author ?: $this->getInfoFieldValues($className, 'author');
                    $this->actions[$actionType][$className]['authorLinks'] = $action->authorLinks;
                }
                $this->actions[$actionType][$className]['name'] = $className;
                $this->actionTypes[$className] = $actionType;
            }
        }
    }

    private function getInfoFieldValues($className, $fieldName) {
        if($this->wire('modules')->isInstalled('AdminActions'.$className)) {
            $actionModuleInfo = $this->wire('modules')->getModuleInfoVerbose('AdminActions'.$className);
            if($fieldName == 'title') {
                return str_replace(array('Admin Actions ', 'Admin Action '), '', $actionModuleInfo[$fieldName]);
            }
            else {
                return isset($actionModuleInfo[$fieldName]) ? $actionModuleInfo[$fieldName] : '';
            }
        }
        else {
            return '';
        }
    }

    private function authorLinks($authorLinks) {

        $pwIcon = '<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
                 viewBox="0 0 39.7 40.1" enable-background="new 0 0 39.7 40.1" xml:space="preserve" width="20px" height="20px">
                    <g>
                        <path fill="none" stroke="#3A4163" stroke-width="4.2" stroke-miterlimit="10" d="M11.6,19.5"/>
                        <path fill="none" stroke="#3A4163" stroke-width="4.2" stroke-miterlimit="10" d="M11.8,22.4"/>
                        <linearGradient id="SVGID_1_" gradientUnits="userSpaceOnUse" x1="16.3309" y1="26.4008" x2="22.7901" y2="25.4266">
                            <stop  offset="0" style="stop-color:#AE3E7D"/>
                            <stop  offset="1" style="stop-color:#EA2262"/>
                        </linearGradient>
                        <path fill="url(#SVGID_1_)" d="M22.1,23.4c-0.6,0.5-1.2,0.7-1.6,0.8c-0.8,0.3-1.7,0.4-2.5,0.3c-0.1,0-0.2,0.1-0.3,0.2l-0.3,1.4
                            c-0.3,1.1,0.3,1.5,0.7,1.6c1.1,0.3,2.1,0.5,3.3,0.4c1.1-0.1,2.2-0.4,3.2-0.9l-1.9-4.5C22.5,23.1,22.3,23.3,22.1,23.4z"/>
                        <path fill="#EA2262" d="M39.3,15.8c-0.4-2.3-1.6-4.9-3-7c-1.2-1.8-3.2-3.9-5.2-5.2c-4.2-2.9-9-3.9-13.3-3.5c-4.5,0.5-8.3,2.2-11.4,5
                            C3.4,7.7,1.5,10.9,0.7,14c-0.9,3.1-0.7,5.9-0.4,8c0.3,2.2,1.4,4.9,1.4,4.9c0.2,0.5,0.5,0.7,0.7,0.8c0.8,0.4,2.1,0,3.1-1.2
                            c-0.3-1.1-0.4-2-0.5-2.6c-0.2-1.2-0.3-3.3-0.2-5.2c0.1-1,0.3-2.1,0.6-3.3c0.7-2.3,2.1-4.7,4.3-6.6c2.4-2.1,5.4-3.4,8.3-3.8
                            c1-0.1,3-0.2,5.3,0.3c0.5,0.1,2.6,0.7,4.9,2.2c1.7,1.1,3,2.6,3.9,3.9c1,1.3,2,3.6,2.3,5.2c0.4,1.9,0.4,3.9,0.1,5.8
                            c-0.4,1.9-1,3.8-2.1,5.5c-0.7,1.3-2.2,3-4,4.2c-1.6,1.1-3.4,2-5.3,2.4c-0.9,0.2-1.9,0.4-2.8,0.4c-0.8,0-2,0-2.8-0.1
                            c-1.2-0.2-1.4-0.5-1.7-0.9c0,0-0.2-0.3-0.2-1.1c0-7.4,0-5.4,0-9.2c0-1.1,0-2.1,0-2.9c0-1.5,0.2-2.5,1.2-3.5c0.7-0.8,1.8-1.3,2.9-1.3
                            c0.3,0,1.6,0,2.6,0.9c1.1,1,1.3,2.3,1.4,2.6c0.2,1.4-0.4,2.6-1,3.3c0.2,1.4,0.7,2.7,1.9,4.5c0.6-0.3,1.3-0.8,1.8-1.3
                            c1.3-1.2,2-2.7,2.3-4.4c0.3-1.9-0.1-3.9-0.9-5.6c-0.9-1.9-2.5-3.4-4.6-4.3c-2.1-0.8-3.8-0.9-6-0.3c-1.4,0.5-2.7,1.1-3.9,2.4
                            c-0.9,0.9-1.6,2-2,3.3c-0.4,1.3-0.5,2.2-0.6,3.7c0,1.1,0,2.1,0,3c0,1,0,2,0,3.1s0,2.1,0,3.1c0,2-0.1,2.3,0,3.3
                            c0,0.7,0.1,1.4,0.4,2.3c0.3,0.9,0.9,1.8,1.4,2.3c0.6,0.7,1.4,1.2,2.1,1.5c1.7,0.8,4.1,0.9,6,0.8c1.3,0,2.5-0.2,3.8-0.5
                            c2.5-0.6,4.9-1.7,7-3.2c2.3-1.6,4.2-3.8,5.3-5.7c1.4-2.2,2.3-4.7,2.8-7.2C39.9,20.9,39.8,18.3,39.3,15.8z"/>
                    </g>
                </svg>';

        $pwforumIcon = '<svg version="1.1" x="0px" y="0px" width="20px" height="20px" viewBox="0 0 20 20" enable-background="new 0 0 20 20" xml:space="preserve">
                            <path fill="#EA2262" d="M10,1.3C4.5,1.3,0,4.8,0,9.1c0,2,1,3.9,2.6,5.3c-0.1,1.3-0.3,3.2-1.3,4.1c1.9,0,3.9-1.2,5-2.1 c1.1,0.4,2.4,0.5,3.7,0.5c5.5,0,10-3.5,10-7.8S15.5,1.3,10,1.3z"/>
                        </svg>';

        $githubIcon = '<svg aria-hidden="true" class="octicon octicon-mark-github" height="20" version="1.1" viewBox="0 0 16 16" width="20"><path fill-rule="evenodd" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"></path></svg>';

        $out = '';
        foreach($authorLinks as $resource => $link) {
            switch ($resource) {
                case 'pwdirectory':
                    if($link != '') $out .= ' <a href="http://directory.processwire.com/developers/'.$link.'/" title="ProcessWire developer profile">'.$pwIcon.'</a>';
                    break;
                case 'pwforum':
                    if($link != '') $out .= ' <a href="https://processwire.com/talk/profile/'.$link.'/" title="ProcessWire forum profile">'.$pwforumIcon.'</a>';
                    break;
                case 'github':
                    if($link != '') $out .= ' <a href="https://github.com/'.$link.'/" title="Github profile">'.$githubIcon.'</a>';
                    break;
            }
        }
        return $out;
    }


    private function getActionTitle($className, $info = null) {
        if($info && isset($info['title'])) {
            $title = $info['title'];
        }
        else {
            $title = preg_replace('/(?<=\\w)(?=[A-Z])/'," $1", $className);
        }
        return $title;
    }


    private function getFunctionCode($source, $functionName) {

        $tokens = token_get_all(file_get_contents($source));

        for($i=0,$z=count($tokens); $i<$z; $i++)
            if(is_array($tokens[$i])
                && $tokens[$i][0] == T_FUNCTION
                && is_array($tokens[$i+1])
                && $tokens[$i+1][0] == T_WHITESPACE
                && is_array($tokens[$i+2])
                && $tokens[$i+2][1] == $functionName)
                    break;

        $accumulator = array();
        // collect tokens from function head through opening brace
        while($tokens[$i] != '{' && ($i < $z)) {
            $accumulator[] = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            $i++;
        }
        if($i == $z) {
            // handle error
        } else {
            // note, accumulate, and position index past brace
            $braceDepth = 1;
            $accumulator[] = '{';
            $i++;
        }
        while($braceDepth > 0 && ($i < $z)) {
            if(is_array($tokens[$i])) {
                $accumulator[] = $tokens[$i][1];
                if($tokens[$i][1] == '{') $braceDepth++;
                elseif($tokens[$i][1] == '}') $braceDepth--;
            }
            else {
                $accumulator[] = $tokens[$i];
                if($tokens[$i] == '{') $braceDepth++;
                elseif($tokens[$i] == '}') $braceDepth--;
            }
            $i++;
        }
        return "\t".implode(null,$accumulator);
    }


    protected function modifyBreadcrumb($event) {
        $this->wire('breadcrumbs')->add(new Breadcrumb('./options?action='.$this->wire('input')->get->action, $this->getActionTitle($this->wire('input')->get->action, $this->data[$this->actionTypes[$this->wire('input')->get->action]][$this->wire('input')->get->action])));
    }


    private function getActionTypes() {
        // populate array of actionType (site or core) for each action
        foreach($this->data as $actionType => $actions) {
            if(is_array($actions)) {
                foreach($actions as $action => $info) {
                    $this->actionTypes[$action] = $actionType;
                }
            }
        }
    }

    private function newActionsAvailable() {
        $siteActionsDir = isset($this->actions['site']) ? $this->actions['site'] : array();
        $siteActionsDB = isset($this->data['site']) ? $this->data['site'] : array();
        $totalCount = count($siteActionsDir) + count($this->actions['core']);
        $installedCount = count($siteActionsDB) + count($this->data['core']);
        if($totalCount > $installedCount) {
            return $totalCount - $installedCount;
        }
        else {
            return false;
        }
    }

    private function backupDb() {
        if(!file_exists($this->adminActionsCacheDir)) wireMkdir($this->adminActionsCacheDir);

        $backup = new WireDatabaseBackup($this->adminActionsCacheDir);
        $backup->setDatabase($this->wire('database'));
        $backup->setDatabaseConfig($this->wire('config'));
        $file = $backup->backup(array('filename' => $this->dbBackupFilename));

        $restoreDbCode =
        "<?php\n" .
        "if(file_exists('".$this->adminActionsCacheDir.$this->dbBackupFilename."')) {\n" .
            "\t\$db = new PDO('mysql:host={$this->wire('config')->dbHost};dbname={$this->wire('config')->dbName}', '{$this->wire('config')->dbUser}', '{$this->wire('config')->dbPass}');\n" .
            "\t\$sql = file_get_contents('" . $this->adminActionsCacheDir . $this->dbBackupFilename . "');\n" .
            "\t\$qr = \$db->query(\$sql);\n" .
        "}\n" .
        "if(isset(\$qr) && \$qr) {\n" .
            "\techo 'The database was successfully restored.';\n" .
        "}\n" .
        "else {\n" .
        "\techo 'Sorry, there was a problem and the database could not be restored.';\n" .
        "}";

        if(!file_put_contents($this->adminActionsCacheDir . 'restoredb.php', $restoreDbCode, LOCK_EX)) throw new WireException("Unable to write file: " . $this->adminActionsCacheDir . 'restoredb.php');
    }


    public function __call($method, $args) {
        $actionFilePath = $this->getActionPath($method);
        if(!file_exists($actionFilePath)) return parent::__call($method, $args);
        require_once $actionFilePath;
        $this->action = new $method;
        $this->action->executeAction($args[0]);
        if(array_key_exists('dbBackup', $args[0])) $this->backupDb();
    }


    /**
     * Return an InputfieldsWrapper of Inputfields used to configure the class
     *
     * @param array $data Array of config values indexed by field name
     * @return InputfieldsWrapper
     *
     */
    public function getModuleConfigInputfields(array $data) {

        $this->wire('modules')->get("JqueryWireTabs");

        $this->wire('config')->scripts->add($this->wire('config')->urls->$this . $this . '.js');
        $this->wire('config')->styles->add($this->wire('config')->urls->$this . $this . '.css');

        $data = array_merge(self::getDefaultData(), $data);
        $allData = array();

        $rolesOptions = array();
        foreach($this->wire('roles')->find("name!=guest") as $role) $rolesOptions[$role->id] = $role->name;

        // get all actions and check to see if there are any new ones not in the module settings database
        $this->getAllActions();
        if($this->wire('input')->get->install !== 1 && isset($this->data['core'])) {
            $this->newActionsAvailable = $this->newActionsAvailable();
            if($this->newActionsAvailable) $this->wire()->warning("There are new actions available (highlighted in orange) - please adjust approved roles and menu status, and click Submit to save.");
        }
        else {
            $this->wire()->warning("Please check the roles and menu status for all the actions and adjust to your needs.");
        }

        if(!array_key_exists('dbBackup', $data)) {
            $allData['dbBackup'] = 'automatic';
        }

        if(!array_key_exists('showActionCode', $data)) {
            $allData['showActionCode'] = null;
        }

        $wrapper = new InputfieldWrapper();

        $f = $this->wire('modules')->get("InputfieldRadios");
        $f->name = 'dbBackup';
        $f->label = __('Database Backup');
        $f->description = __('Do you want to backup the database before each action is executed?');
        $f->notes = __('You can override this setting in your custom actions by setting: protected $dbBackup = "automatic", "optional", or "disabled".');
        $f->required = true;
        $f->addOption('automatic', 'Automatic');
        $f->addOption('optional', 'Optional');
        $f->addOption('disabled', 'Disabled');
        $f->columnWidth = 50;
        $f->attr('value', array_key_exists('dbBackup', $data) ? $data['dbBackup'] : $allData['dbBackup']);
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->name = 'showActionCode';
        $f->label = __('Show Action Code');
        $f->description = __('Show the code for the executeAction() function for superusers.');
        $f->columnWidth = 50;
        $showActionCode = array_key_exists('showActionCode', $data) ? $data['showActionCode'] : $allData['showActionCode'];
        $f->attr('checked', $showActionCode ? 'checked' : '' );
        $wrapper->add($f);

        $fieldset = $this->wire('modules')->get("InputfieldFieldset");
        $fieldset->name = 'instructions';
        $fieldset->label = __('Action settings');
        $fieldset->value = "<p>Choose roles and check whether you want the action available from the flyout menu.<br />Remember that the roles must also have the 'admin-actions' permission to use this module.</p><p>Actions can be executed via Setup > Admin Actions, or via the API.</p>
        <div id='links'><a href='".$this->processPageUrl."'><i class='fa fa-cog'></i> Actions List</a></div>
        ";
        $wrapper->add($fieldset);

        $tabsWrapper = new InputfieldWrapper();
        $tabsWrapper->attr("id", "AdminActionsList");

        if(isset($this->actions['site'])) ksort($this->actions['site']);
        ksort($this->actions['core']);

        $actionsCount=0;
        foreach($this->actions as $actionType => $actionsList) {

            if(!is_array($actionsList)) continue;

            $tab = new InputfieldWrapper();
            $tab->attr('id', 'tab_'.$actionType.'_actions');
            $tab->attr('title', ucfirst($actionType));
            $tab->attr('class', 'WireTab');

            $f = $this->wire('modules')->get("InputfieldMarkup");
            $f->attr('name', 'table');
            $table = $this->wire('modules')->get("MarkupAdminDataTable");
            $table->setEncodeEntities(false);
            $table->setSortable(true);
            $table->setClass('adminactionsconfig');
            $table->headerRow(array(
                __('Title'),
                __('Info'),
                __('Roles'),
                sprintf(__('In%sMenu', __FILE__), '&nbsp;'),
                __('Author')
            ));

            foreach($actionsList as $action => $info) {
                $actionTitle = $this->getActionTitle($action, $info);
                if(empty($data) || !isset($data['core']) || !isset($data[$actionType][$info['name']])) {
                    $rolesValue = array($this->wire('roles')->get('superuser')->id);
                }
                else {
                    $rolesValue = isset($data[$actionType][$info['name']]['roles']) ? $data[$actionType][$info['name']]['roles'] : null;
                }
                $allData[$actionType][$info['name']]['roles'] = $rolesValue;

                $rolesWrapper = new InputfieldWrapper();
                $fr = $this->wire('modules')->get("InputfieldAsmSelect");
                $fr->attr('name', 'roles_'.$info['name']);
                $fr->skipLabel = Inputfield::skipLabelHeader;
                $fr->options = $rolesOptions;
                $fr->addClass('rolesSelect');
                $fr->setAsmSelectOption('sortable', false);
                $fr->value = $rolesValue;
                $rolesWrapper->add($fr);

                $menuWrapper = new InputfieldWrapper();
                $fm = $this->wire('modules')->get("InputfieldCheckbox");
                $fm->attr('name', 'menu_'.$info['name']);
                $fm->skipLabel = Inputfield::skipLabelHeader;
                $fm->label = ' ';
                $fm->attr('checked', isset($data[$actionType][$info['name']]['menu']) && $data[$actionType][$info['name']]['menu'] == '1' ? 'checked' : '' );
                $menuWrapper->add($fm);
                $siteActions = isset($data['site']) ? $data['site'] : array();
                $rowClass = isset($data['core']) && !array_key_exists($action, $siteActions) && !array_key_exists($action, $data['core']) ? 'newAction' : '';
                $row = array(
                    '<strong><a href="'.$this->processPageUrl.'options?action='.$action.'">'.($info['icon'] ? '<i class="fa fa-'.$info['icon'].'"></i> ' : '').' '.$actionTitle.'</a></strong>' . ($this->wire('modules')->isInstalled('AdminActions'.$action) ? '&nbsp;&nbsp;&nbsp;<a title="AdminActions'.$actionTitle.' settings" href="'.$this->wire('config')->urls->admin . 'module/edit?name=AdminActions'.$action.'"><i class="fa fa-cog"></i></a>' : ''),
                    (isset($info['description']) ? $info['description'] : '') . (isset($info['notes']) ? '<br /><span class="notes">'.$info['notes'].'</span>' : ''),
                    $rolesWrapper->render(),
                    $menuWrapper->render(),
                    isset($info['author']) ? '<span class="adminActionsAuthor">'.$info['author'].'<br />' . ($info['authorLinks'] ? $this->authorLinks($info['authorLinks']) : '') . '</span>' : ''

                );
                $table->row($row, array('class' => $rowClass));
                $actionsCount++;

                // prepare initial description, notes, and author values
                $allData[$actionType][$info['name']]['title'] = $actionTitle;
                $allData[$actionType][$info['name']]['description'] = $info['description'];
                $allData[$actionType][$info['name']]['notes'] = $info['notes'];
                $allData[$actionType][$info['name']]['icon'] = $info['icon'];
                $allData[$actionType][$info['name']]['author'] = $info['author'];
                $allData[$actionType][$info['name']]['authorLinks'] = $info['authorLinks'];

            }
            $f->attr('value', $table->render());
            $tab->add($f);

            $tabsWrapper->add($tab);
        }
        $fieldset->add($tabsWrapper);
        $wrapper->add($fieldset);

        // save initial data when $data is empty on module install
        // or we have redirected from Setup > Admin Actions to install the new actions
        if(empty($data) || $this->wire('input')->get->install == 1) $this->wire('modules')->saveModuleConfigData($this, $allData);

        // if we have redirected from Setup > Admin Actions to install the new actions
        // then redirect back to this page now that we have saved the config data
        if($this->wire('input')->get->install == 1) {
            $this->wire->warnings('clear');
            $this->wire('session')->redirect($this->processPageUrl.'?installed='.$this->newActionsAvailable);
        }

        // modify what is stored when settings saved so we can have the multidimensional array of data
        $this->wire('modules')->addHookBefore('saveModuleConfigData', null, function($event) use ($allData) {
            if($this->wire('input')->get->name != $this) return;

            $this->wire()->errors('clear');
            $this->wire()->warnings('clear');

            $allData['dbBackup'] = $this->wire('input')->post->dbBackup;
            $allData['showActionCode'] = $this->wire('input')->post->showActionCode;
            foreach($this->wire('input')->post as $action => $info) {
                if (strpos($action, 'roles_') !== false) {
                    $action = str_replace('roles_', '', $action);
                    if(isset($this->actionTypes[$action]) && isset($allData[$this->actionTypes[$action]][$action])) {
                        $allData[$this->actionTypes[$action]][$action]['roles'] = $info;
                    }
                }
                if (strpos($action, 'menu_') !== false) {
                    $action = str_replace('menu_', '', $action);
                    if(isset($allData[$this->actionTypes[$action]][$action])) $allData[$this->actionTypes[$action]][$action]['menu'] = $info;
                }
            }
            $event->arguments(1, $allData);
        });

        return $wrapper;
    }

}
