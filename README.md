# Admin Actions

Processwire module for running various admin actions


### Support forum:
https://processwire.com/talk/topic/14921-admin-actions/

### Introduction

Admin Actions lets you quickly create actions in the admin that you can use over and over and even make available to your site editors (permissions for each action are assigned to roles separately so you have full control over who has access to which actions).

### Included Actions

It comes bundled with a several actions and I will be adding more over time (and hopefully I'll get some PRs from you guys too). You can browse and sort and filter based on the content of all columns.

**Convert Fields to Multi-Language**
Converts fields to their matching multi-language fieldtype.

**Copy Content To Other Field**
This action copies the content from one field to another field on all pages that use the selected template.

**Copy Field Content To Other Page**
Copies the content from a field on one page to the same field on another page.

**Copy Repeater Items To Other Page**
Add the items from a Repeater field on one page to the same field on another page.

**Copy Table Field Rows To Other Page**
Add the rows from a Table field on one page to the same field on another page.

**Create Users Batcher**
Allows you to batch create users. This module requires the Email New User module and it should be configured to generate a password automatically.

**Delete Unused Fields**
Deletes fields that are not used by any templates.

**Delete Unused Templates**
Deletes templates that are not used by any pages.

**Email Batcher**
Lets you email multiple addresses at once.

**Field Set Or Search And Replace**
Set field values, or search and replace text in field values from a filtered selection of pages and fields.

**FTP Files to Page**
Add files/images from a folder to a selected page.

**Page Active Languages Batcher**
Lets you enable or disable active status of multiple languages on multiple pages at once.

**Page Manipulator**
Uses an InputfieldSelector to query pages and then allows batch actions on the matched pages.

**Page Table To Repeater Matrix**
Fully converts an existing (and populated) PageTable field to either a Repeater or RepeaterMatrix field.

**Template Fields Batcher**
Lets you add or remove multiple fields from multiple templates at once.

**Template Roles Batcher**
Lets you add or remove access permissions, for multiple roles and multiple templates at once.

**User Roles Permissions Batcher**
Lets you add or remove permissions for multiple roles, or roles for multiple users at once.

### Creating a New Action

#### Custom Site Actions
You can create new actions and place them in /site/templates/AdminActions/ so they don't get lost on module updates. These modules will appear under a new "Site" tab on the actions table.

#### Shared Site Actions
If you create a new action that you think others would find useful, please create a new AdminActions module and submit to the ProcessWire modules directory.

Shared actions to be shared must include a PW module file. There are two requirements:

1. It's classname must start with "AdminActions"
2. It must have `'requires' => 'ProcessAdminActions'`

This is the entire contents needed in a file named AdminActionsMySharedAction.module

Once this module is installed on a site, AdminActions will detect it and allow users to configure and use it like any other action.

```
class AdminActionsMySharedAction extends WireData implements Module {

    public static function getModuleInfo() {
        return array(
            'title' => 'Admin Actions My Shared Action',
            'summary' => 'My new action does really cool stuff',
            'version' => '0.1.0',
            'author' => 'John Doe',
            'href' => 'https://github.com/JohnDoe/AdminActionsMySharedAction',
            'requires' => 'ProcessAdminActions'
        );
    }

}
```

A good example of an installable action is @Toutouwai's [Unordered List to Pages](https://processwire.com/talk/topic/20025-unordered-list-to-pages-action-for-admin-actions/)


#### Custom action examples

A new action file can be as simple as this:

```
class UnpublishAboutPage extends ProcessAdminActions {

    protected function executeAction() {
        $p = $this->wire('pages')->get('/about/');
        $p->addStatus(Page::statusUnpublished);
        $p->save();
        return true;
    }

}
```

Each action:

* class must extend "ProcessAdminActions" and the filename must match the class name and end in ".action.php" like: UnpublishAboutPage.action.php
* the action method must be: executeAction()

As you can see there are only a few lines needed to wrap the actual API call, so it's really worth the small extra effort to make an action.

Obviously that example action is not very useful. Here is another more useful one that is included with the module. It includes $description, $notes, and $author variables which are used in the module table selector interface. It also makes use of the defineOptions() method which builds the input fields used to gather the required options before running the action.

```
class DeleteUnusedFields extends ProcessAdminActions {

    protected $description = 'Deletes fields that are not used by any templates.';
    protected $notes = 'Shows a list of unused fields with checkboxes to select those to delete.';
    protected $author = 'Adrian Jones';
    protected $authorLinks = array(
        'pwforum' => '985-adrian',
        'pwdirectory' => 'adrian-jones',
        'github' => 'adrianbj',
    );

    protected function defineOptions() {

        $fieldOptions = array();
        foreach($this->wire('fields') as $field) {
            if ($field->flags & Field::flagSystem || $field->flags & Field::flagPermanent) continue;
            if(count($field->getFieldgroups()) === 0) $fieldOptions[$field->id] = $field->label ? $field->label . ' (' . $field->name . ')' : $field->name;
        }

        return array(
            array(
                'name' => 'fields',
                'label' => 'Fields',
                'description' => 'Select the fields you want to delete',
                'notes' => 'Note that all fields listed are not used by any templates and should therefore be safe to delete',
                'type' => 'checkboxes',
                'options' => $fieldOptions,
                'required' => true
            )
        );

    }


    protected function executeAction($options) {

        $count = 0;
        foreach($options['fields'] as $field) {
            $f = $this->wire('fields')->get($field);
            $this->wire('fields')->delete($f);
            $count++;
        }

        $this->successMessage = $count . ' field' . _n('', 's', $count) . ' ' . _n('was', 'were', $count) . ' successfully deleted';
        return true;

    }

}
```

Finally we use $options array in the executeAction() method to get the values entered into those options fields to run the API script to remove the checked fields.

There is one additional method that I didn't outline called: checkRequirements() - you can see it in action in the PageTableToRepeaterMatrix action. You can use this to prevent the action from running if certain requirements are not met.

At the end of the executeAction() method you can populate $this->successMessage, or $this->failureMessage which will be returned after the action has finished.

### Populating options via URL parameters

You can also populate the option parameters via URL parameters. You should split multiple values with a "|" character.

You can either just pre-populate options:
```
http://mysite.dev/processwire/setup/admin-actions/options?action=TemplateFieldsBatcher&templates=29|56&fields=219&addOrRemove=add
```
or you can execute immediately:
```
http://mysite.dev/processwire/setup/admin-actions/run?action=TemplateFieldsBatcher&templates=29|56&fields=219&addOrRemove=add
```
Note the "options" vs "run" as the last path segment before the parameters.

### Calling an action via the API

You can call an action easily via the API. Here's an example of copying content of a field from one page to another.

Simply load the module, and call the action name as a method while passing the required options as an array:
```
$options = array(
   'field' => 99,
   'sourcePage' => 1131,
   'destinationPage' => 1132
);
$modules->get("ProcessAdminActions")->CopyFieldContentToOtherPage($options);
```

### Automatic Backup / Restore

Before any action is executed, a full database backup is automatically made. You have a few options to run a restore if needed:

* Follow the Restore link that is presented after an action completes
* Use the "Restore" submenu: Setup > Admin Actions > Restore
* Move the restoredb.php file from the /site/assets/cache/AdminActions/ folder to the root of your site and load in the browser
* Manually restore using the AdminActionsBackup.sql file in the /site/assets/cache/AdminActions/ folder

## License

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

(See included LICENSE file for full license text.)

