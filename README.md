# Admin Actions #

Processwire module for running various admin actions


#### Support forum:
https://processwire.com/talk/topic/14921-admin-actions/

#### Introduction

Admin Actions lets you quickly create actions in the admin that you can use over and over and even make available to your site editors (permissions for each action are assigned to roles separately so you have full control over who has access to which actions).

#### Included Actions

It comes bundled with a several actions and I will be adding more over time (and hopefully I'll get some PRs from you guys too). You can browse and sort and filter based on the content of all columns.

#### Creating a New Action

If you create a new action that you think others would find useful, please add it to the "actions" subfolder of this module and submit a PR. If you think it is only useful for you, place it in /site/templates/AdminActions/ so that it doesn't get lost on module updates.

A new action file can be as simple as this:

```
class UnpublishAboutPage extends ProcessAdminActions {

    protected function executeAction($options) {
        $p = $this->pages->get('/about/');
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

    protected function defineOptions() {

        $fieldOptions = array();
        foreach($this->fields as $field) {
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
            $f = $this->fields->get($field);
            $this->fields->delete($f);
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

#### Automatic Backup / Restore

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

