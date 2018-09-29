<?php

class FtpFilesToPage extends ProcessAdminActions {

    protected $title = 'FTP Files to Page';
    protected $description = 'Add files/images from a folder to a selected page.';
    protected $notes = 'This is useful if you want to FTP files, rather than upload via the admin.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected $executeButtonLabel = 'Add Files to Page';
    protected $icon = 'upload';

    protected function defineOptions() {

        $folderOptions = array();
        $adminActionsCacheDir = $this->wire('config')->paths->cache . 'AdminActions/';
        if(!file_exists($adminActionsCacheDir)) wireMkdir($adminActionsCacheDir);

        $dir = new \DirectoryIterator($adminActionsCacheDir);

        foreach($dir as $item) {
            if(!$item->isDir() || $item->isDot()) continue;
            $folderOptions[$item->getPathname()] = $item->getFilename();
        }

        return array(
            array(
                'name' => 'sourceFolder',
                'label' => 'Source Folder',
                'description' => 'The source folder for the files',
                'notes' => (empty($folderOptions) ? 'You need to add files to a subfolder you have created under /site/assets/cache/AdminActions/' : ''),
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
                'options' => $this->wire('fields')->find("type=FieldtypeFile|FieldtypeImage")->getArray()
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
        $fieldName = $this->wire('fields')->get((int)$options['field'])->name;
        $destinationPage = $this->wire('pages')->get((int)$options['destinationPage']);
        $deleteFolder = (bool)$options['deleteFolder'];

        $destinationPage->of(false);
        $dir = new \DirectoryIterator($sourceFolder);
        $numFiles = 0;
        foreach($dir as $item) {
            if($item->isDir() || $item->isDot()) continue;
            $destinationPage->$fieldName->add($item->getPathname());
            if($deleteFolder) unlink($item->getPathname());
            $numFiles++;
        }
        $destinationPage->save($fieldName);

        if($deleteFolder) rmdir($sourceFolder);

        $this->successMessage = $numFiles . ' files were successfully added to the ' . $fieldName . ' field on the <a href="'.$destinationPage->editUrl.'">' . $destinationPage->path . '</a> page.';
        return true;

    }

}