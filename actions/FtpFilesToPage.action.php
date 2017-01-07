<?php

class FtpFilesToPage extends ProcessAdminActions {

    protected $description = 'Add files/images from a folder to a selected page.';
    protected $notes = 'This is useful if you want to FTP files, rather than upload via the admin.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected function defineOptions() {

        $folderOptions = array();
        $dir = new \DirectoryIterator($this->config->paths->cache.'AdminActions/');
        foreach($dir as $item) {
            if(!$item->isDir() || $item->isDot()) continue;
            $folderOptions[$item->getPathName()] = $item->getFilename();
        }

        return array(
            array(
                'name' => 'sourceFolder',
                'label' => 'Source Folder',
                'description' => 'The source folder for the files',
                'notes' => 'You can choose any subfolder of /site/assets/cache/AdminActions/',
                'type' => 'select',
                'options' => $folderOptions,
                'required' => true
            ),
            array(
                'name' => 'field',
                'label' => 'Field',
                'description' => 'Choose the field that you want the files added to',
                'type' => 'select',
                'required' => true,
                'options' => $this->fields->find("type=FieldtypeFile|FieldtypeImage")->getArray()
            ),
            array(
                'name' => 'destinationPage',
                'label' => 'Destination Page',
                'description' => 'The destination page for the files',
                'type' => 'pageListSelect',
                'required' => true
            ),
            array(
                'name' => 'deleteFolder',
                'label' => 'Delete Folder',
                'description' => 'Delete folder (including all files) after all files/images have been added to the page?',
                'type' => 'checkbox'
            )
        );
    }


    protected function executeAction($options) {

        $sourceFolder = $options['sourceFolder'];
        $fieldName = $this->fields->get((int)$options['field'])->name;
        $destinationPage = $this->pages->get((int)$options['destinationPage']);
        $deleteFolder = (bool)$options['deleteFolder'];

        $destinationPage->of(false);
        $dir = new \DirectoryIterator($sourceFolder);
        $numFiles = 0;
        foreach($dir as $item) {
            if($item->isDir() || $item->isDot()) continue;
            $destinationPage->$fieldName->add($item->getPathName());
            if($deleteFolder) unlink($item->getPathName());
            $numFiles++;
        }
        $destinationPage->save($fieldName);

        if($deleteFolder) rmdir($sourceFolder);

        $this->successMessage = $numFiles . ' files were successfully added to the ' . $fieldName . ' field on the <a href="'.$destinationPage->editUrl.'">' . $destinationPage->path . '</a> page.';
        return true;

    }

}