<?php
/*
 * This file is part of Totara LMS
 *
 * Copyright (C) 2010, 2011 Totara Learning Solutions LTD
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Simon Coggins <simonc@catalyst.net.nz>
 * @package totara
 * @subpackage plan
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

//TODO: Uncomment once totara messages are finished
require_once ($CFG->dirroot.'/totara/plan/lib.php');


class development_plan {
    public static $permissions = array(
        'view' => false,
        'create' => false,
        'update' => false,
        'delete' => false,
        'approve' => true,
        'completereactivate' => false
    );
    public $id, $templateid, $userid, $name, $description;
    public $startdate, $enddate, $timecompleted, $status, $role, $settings;
    public $viewas;

    /**
     * Flag the page viewing this plan as the reviewing pending page
     *
     * @access  public
     * @var     boolean
     */
    public $reviewing_pending = false;


    function __construct($id, $viewas=null) {
        global $USER, $CFG;

        // get plan db record
        $plan = get_record('dp_plan', 'id', $id);
        if(!$plan) {
            throw new PlanException(get_string('planidnotfound','local_plan', $id));
        }

        // get details about this plan
        $this->id = $id;
        $this->templateid = $plan->templateid;
        $this->userid = $plan->userid;
        $this->name = $plan->name;
        $this->description = $plan->description;
        $this->startdate = $plan->startdate;
        $this->enddate = $plan->enddate;
        $this->timecompleted = $plan->timecompleted;
        $this->status = $plan->status;

        // default to viewing as the current user
        // if $viewas not set
        if(empty($viewas)) {
            $this->viewas = $USER->id;
        } else {
            $this->viewas  = $viewas;
        }

        // store role and component objects for easy access
        $this->load_roles();
        $this->load_components();

        // get the user's role in this plan
        $this->role = $this->get_user_role($this->viewas);

        // lazy-load settings from database when first needed
        $this->settings = null;
    }


    /**
     * Is this plan currently active?
     *
     * @access  public
     * @return  boolean
     */
    public function is_active() {
        return $this->status == DP_PLAN_STATUS_APPROVED;
    }


    /**
     * Is this plan complete?
     *
     * @access  public
     * @return  boolean
     */
    public function is_complete() {
        return $this->status == DP_PLAN_STATUS_COMPLETE;
    }


    /**
     * Save an instance of each defined role to a property of this class
     *
     * This method creates a property $this->[role] for each entry in
     * $DP_AVAILABLE_ROLES, and fills it with an instance of that role.
     *
     */
    function load_roles() {

        global $CFG, $DP_AVAILABLE_ROLES;

        // loop through available roles
        foreach ($DP_AVAILABLE_ROLES as $role) {
            // include each class file
            $classfile = $CFG->dirroot .
                "/totara/plan/roles/{$role}/{$role}.class.php";
            if(!is_readable($classfile)) {
                $string_params = new object();
                $string_params->classfile = $classfile;
                $string_params->role = $role;
                throw new PlanException(get_string('noclassfileforrole', 'local_plan', $string_params));
            }
            include_once($classfile);

            // check class exists
            $class = "dp_{$role}_role";
            if(!class_exists($class)) {
                $string_params = new object();
                $string_params->class = $class;
                $string_params->role = $role;
                throw new PlanException(get_string('noclassforrole', 'local_plan', $string_params));
            }

            $rolename = "role_$role";

            // create an instance and save as a property for easy access
            $this->$rolename = new $class($this);
        }

    }


    /**
     * Get the rolename from the role
     *
     * @param string $role
     * @return string $rolename
     */
    function get_role($role) {
        $rolename = "role_$role";
        return $this->$rolename;
    }


    /**
     * Save an instance of each defined component to a property of this class
     *
     * This method creates a property $this->[component] for each entry in
     * $DP_AVAILABLE_COMPONENTS, and fills it with an instance of that component.
     *
     */
    function load_components() {
        global $CFG, $DP_AVAILABLE_COMPONENTS;

        foreach($DP_AVAILABLE_COMPONENTS as $component) {
            // include each class file
            $classfile = $CFG->dirroot .
                "/totara/plan/components/{$component}/{$component}.class.php";
            if(!is_readable($classfile)) {
                $string_params = new object();
                $string_params->classfile = $classfile;
                $string_params->component = $component;
                throw new PlanException(get_string('noclassfileforcomponent', 'local_plan', $string_params));
            }
            include_once($classfile);

            // check class exists
            $class = "dp_{$component}_component";
            if(!class_exists($class)) {
                $string_params = new object();
                $string_params->class = $class;
                $string_params->component = $component;
                throw new PlanException(get_string('noclassforcomponent', 'local_plan', $string_params));
            }

            $componentname = "component_$component";

            // create an instance and save as a property for easy access
            $this->$componentname = new $class($this);
        }
    }


    /**
     * Return a single component
     *
     * @access  public
     * @param   string  $component  Component name
     * @return  object
     */
    public function get_component($component) {
        $componentname = "component_$component";
        return $this->$componentname;
    }


    /**
     * Return array of active component instances for a plan template
     *
     * @access  public
     * @return  array
     */
    public function get_components() {
        global $CFG, $DP_AVAILABLE_COMPONENTS;

        $sql = "SELECT * FROM {$CFG->prefix}dp_component_settings
            WHERE component IN ('".implode("','", $DP_AVAILABLE_COMPONENTS)."')
            AND templateid = {$this->templateid}
            AND enabled = 1
            ORDER BY sortorder";
        $active_components = get_records_sql($sql);

        $components = array();
        foreach ($active_components as $component) {
            $componentname = "component_$component->component";
            $components[$component->component] = $this->$componentname;
        }

        return $components;
    }


    /**
     * Get a component setting
     *
     * @param array $component
     * @param string $action
     * @return void
     */
    function get_component_setting($component, $action) {
        // we need the know the template to get settings
        if(!$this->templateid) {
            return false;
        }
        $role = $this->role;
        $templateid = $this->templateid;

        // only load settings when first needed
        if(!isset($this->settings)) {
            $this->initialize_settings();
        }

        // return false the setting if it exists
        if(array_key_exists($component.'_'.$action, $this->settings)) {
            return $this->settings[$component.'_'.$action];
        }

        // return the role specific setting if it exists
        if(array_key_exists($component.'_'.$action.'_'.$role, $this->settings)) {
            return $this->settings[$component.'_'.$action.'_'.$role];
        }

        // return null if nothing set
        print_error('error:settingdoesnotexist', 'local_plan', '', (object)array('component'=>$component, 'action'=>$action));
    }


    /**
     * Get a setting for an action
     *
     * @param string $action
     * @return string
     */
    function get_setting($action) {
        return $this->get_component_setting('plan', $action);
    }


    /**
     * Initialize settings for a component
     *
     * @return bool
     */
    function initialize_settings() {
        global $DP_AVAILABLE_COMPONENTS;
        // no need to initialize twice
        if(isset($this->settings)) {
            return true;
        }
        // can't initialize without a template id
        if(!$this->templateid) {
            return false;
        }

        // add role-based settings from permissions table
        if($results = get_records('dp_permissions', 'templateid',
            $this->templateid)) {

            foreach($results as $result) {
                $this->settings[$result->component.'_'.$result->action.'_'.$result->role] = $result->value;
            }
        }

        // add component-independent settings
        if ($components = get_records('dp_component_settings', 'templateid', $this->templateid, 'sortorder')) {
            foreach($components as $component) {
                // is this component enabled?
                $this->settings[$component->component.'_enabled'] = $component->enabled;

                // get the name from a config var, or use the default name if not set
                $configname = 'dp_'.$component->component;
                $name = get_config(null, $configname);
                $this->settings[$component->component.'_name'] = $name ? $name :
                    get_string($component->component, 'local_plan');
            }
        }

        // also save the whole list together with sort order
        $this->settings['plan_components'] = $components;

        // add role-independent settings from individual component tables
        foreach($DP_AVAILABLE_COMPONENTS as $component) {
            // only include if the component is enabled
            if(!$this->get_component($component)->get_setting('enabled')) {
                continue;
            }
            $this->get_component($component)->initialize_settings($this->settings);
        }

        // Initialise plan specific settings
        if ($plansettings = get_record('dp_plan_settings', 'templateid', $this->templateid)) {
            $this->settings['plan_manualcomplete'] = $plansettings->manualcomplete;
            $this->settings['plan_autobyitems'] = $plansettings->autobyitems;
            $this->settings['plan_autobyplandate'] = $plansettings->autobyplandate;
        }
    }


    /**
     * Find the number of pending items this use can approve, if any
     *
     * @access  public
     * @return  integer
     */
    public function num_pendingitems() {
        // Check if plan is active
        if ($this->status == DP_PLAN_STATUS_COMPLETE) {
            return 0;
        }

        // Get all pending items
        $items = $this->has_pending_items(null, true, true);

        if (!$items) {
            return 0;
        }

        // Count all
        $count = 0;
        foreach ($items as $component) {
            $count += count($component);
        }

        return $count;
    }


    /**
     * Display widget containing a component summary
     *
     * @return string $out
     */
    function display_summary_widget() {
        global $CFG;

        $out = '';
        $out .= "<div class=\"dp-summary-widget-title\"><a href=\"{$CFG->wwwroot}/totara/plan/view.php?id={$this->id}\">{$this->name} </a></div>";
        $components = get_records_select('dp_component_settings', "templateid={$this->templateid} AND enabled=1", 'sortorder');
        $total = count($components);
        $pendingitems = $this->num_pendingitems();
        $out .= "<div class=\"dp-summary-widget-components\">";
        if ($components) {
            $count = 1;
            foreach ($components as $c) {
                $component = $this->get_component($c->component);
                $compname = get_string($component->component.'plural', 'local_plan');
                $class = ($count == $total && !$pendingitems) ? "dp-summary-widget-component-name-last" : "dp-summary-widget-component-name";
                $assignments = $component->get_assigned_items();
                $assignments = !empty($assignments) ? '('.count($assignments).')' : '';

                $out .= "<span class=\"{$class}\">";
                $out .= '<a href="'.$component->get_url().'">';
                $out .= $compname;

                if ($assignments) {
                    $out .= " $assignments";
                }

                $out .= "</a> </span>";
                $count++;
            }

            if ($pendingitems) {
                $out .= '<span class="dp-summary-widget-pendingitems-text">'.get_string('pendingitemsx', 'local_plan',
                    (object)array('count'=>$pendingitems,
                    'link'=>"{$CFG->wwwroot}/totara/plan/approve.php?id={$this->id}")).'</span>';
            }


        }
        $out .= "</div>";

        $out .= "<div class=\"dp-summary-widget-description\">{$this->description}</div>";

        return $out;
    }


    /**
     * Display the add plan icon
     *
     * @return string $out
     */
    function display_add_plan_icon() {
        global $CFG;

        $out = '';
        $out .= "<a href=\"{$CFG->wwwroot}/totara/plan/edit.php\"><img src=\"{$CFG->wwwtheme}/pix/t/add.gif\" title=\"".get_string('addplan', 'local_plan')."\" alt=\"".get_string('addplan', 'local_plan')."\"/></a>";

        return $out;
    }


    /**
     * Display end date for an item
     *
     * @param int $itemid
     * @param int $enddate
     * @return string
     */
    function display_enddate() {
        $out = '';

        $out .= $this->display_enddate_as_text($this->enddate);

        // highlight dates that are overdue or due soon
        $out .= $this->display_enddate_highlight_info($this->enddate);

        return $out;

    }


    /**
     * Display enddate for an item as text
     *
     * @param int $enddate
     * @return string
     */
    function display_enddate_as_text($enddate) {
        global $CFG;
        if(isset($enddate)) {
            return userdate($enddate, '%e %h %Y', $CFG->timezone, false);
        } else {
            return '';
        }
    }


    /**
     * Display enddate for an item with task info
     *
     * @param int $enddate
     * @return string
     */
    function display_enddate_highlight_info($enddate) {
        $out = '';
        $now = time();
        if(isset($enddate)) {
            if(($enddate < $now) && ($now - $enddate < 60*60*24)) {
                $out .= '<br /><span class="plan_highlight">' . get_string('duetoday', 'local_plan') . '</span>';
            } else if($enddate < $now) {
                $out .= '<br /><span class="plan_highlight">' . get_string('overdue', 'local_plan') . '</span>';
            } else if ($enddate - $now < 60*60*24*7) {
                $days = ceil(($enddate - $now)/(60*60*24));
                $out .= '<br /><span class="plan_highlight">' . get_string('dueinxdays', 'local_plan', $days) . '</span>';
            }
        }
        return $out;
    }

    /**
     * Determines and displays the progress of this plan.
     *
     * Progress is determined by course completion statuses.
     *
     * @access  public
     * @return  string
     */
    public function display_progress() {
        global $CFG;

        if ($this->status == DP_PLAN_STATUS_UNAPPROVED) {
            $out = get_string('planstatusunapproved', 'local_plan');
            // Approval request
            if ($this->get_setting('approve') == DP_PERMISSION_REQUEST) {
                $out .= '<br /><a href="'.$CFG->wwwroot.'/totara/plan/action.php?id='.$this->id.'&amp;approvalrequest=1&amp;sesskey='.sesskey().'" title="'.get_string('sendapprovalrequest', 'local_plan').'">'.
                    get_string('requestapproval', 'local_plan').
                    '</a>';
            }


            return $out;
        }

        $overall_complete = 0;
        $overall_total = 0;
        $overall_strings = array();
        foreach($this->get_components() as $component) {
            if($stats = $component->progress_stats()) {
                $overall_complete += $stats->complete;
                $overall_total += $stats->total;
                $overall_strings[] = $stats->text;
            }
        }

        // Calculate plan progress
        if($overall_complete > 0) {
            $overall_progress = $overall_complete / $overall_total * 100.0;
            $overall_progress = round($overall_progress, 2);
        } else {
            $overall_progress = $overall_complete;
        }

        array_unshift($overall_strings, 'Plan Progress: ' . $overall_progress . "%\n\n");
        $tooltipstr = implode(' | ', $overall_strings);

        // Get relevant progress bar and return for display
        return local_display_progressbar($overall_progress, 'medium', false, $tooltipstr);
    }


    /**
     * Display completed date for a plan
     *
     * @return string
     */
    function display_completeddate() {
        global $CFG;

        // Ensure plan is currently completed
        if ($this->status != DP_PLAN_STATUS_COMPLETE) {
            return get_string('notcompleted', 'local_plan');
        }

        // Get the last modification and make sure that it has DP_PLAN_STATUS_COMPLETE status
        $history = $this->get_history('id DESC');
        $latestmodification = reset($history);

        return ($latestmodification->status != DP_PLAN_STATUS_COMPLETE) ? get_string('notcompleted', 'local_plan') : userdate($latestmodification->timemodified, '%d/%m/%y', $CFG->timezone, false);
    }


    /**
     *  Displays icons of current actions the user can perform on the plan
     *
     *  @return string
     */
    function display_actions() {
        global $CFG;

        ob_start();

        // Approval
        if ($this->status == DP_PLAN_STATUS_UNAPPROVED) {

            // Approve/Decline
            if (in_array($this->get_setting('approve'), array(DP_PERMISSION_ALLOW, DP_PERMISSION_APPROVE))) {
                echo '<a href="'.$CFG->wwwroot.'/totara/plan/action.php?id='.$this->id.'&amp;approve=1&amp;sesskey='.sesskey().'" title="'.get_string('approve', 'local_plan').'">
                    <img src="'.$CFG->pixpath.'/t/go.gif" alt="'.get_string('approve', 'local_plan').'" />
                    </a>';
                echo '<a href="'.$CFG->wwwroot.'/totara/plan/action.php?id='.$this->id.'&amp;decline=1&amp;sesskey='.sesskey().'" title="'.get_string('decline', 'local_plan').'">
                    <img src="'.$CFG->pixpath.'/t/stop.gif" alt="'.get_string('decline', 'local_plan').'" />
                    </a>';
            }
        }

        // Complete
        if ($this->status == DP_PLAN_STATUS_APPROVED && $this->get_setting('completereactivate') >= DP_PERMISSION_ALLOW  && $this->get_setting('manualcomplete')) {
            echo '<a href="'.$CFG->wwwroot.'/totara/plan/action.php?id='.$this->id.'&amp;complete=1&amp;sesskey='.sesskey().'" title="'.get_string('plancomplete', 'local_plan').'">
                <img src="'.$CFG->pixpath.'/t/favourite_on.gif" alt="'.get_string('plancomplete', 'local_plan').'" />
                </a>';
        }

        // Reactivate
        if ($this->status == DP_PLAN_STATUS_COMPLETE && $this->get_setting('completereactivate') >= DP_PERMISSION_ALLOW) {
            echo '<a href="'.$CFG->wwwroot.'/totara/plan/action.php?id='.$this->id.'&amp;reactivate=1&amp;sesskey='.sesskey().'" title="'.get_string('planreactivate', 'local_plan').'">
                <img src="'.$CFG->pixpath.'/t/plan_reactivate.gif" alt="'.get_string('planreactivate', 'local_plan').'" />
                </a>';
        }

        // Delete
        if ($this->get_setting('delete') == DP_PERMISSION_ALLOW) {
            echo '<a href="'.$CFG->wwwroot.'/totara/plan/action.php?id='.$this->id.'&amp;delete=1&amp;sesskey='.sesskey().'" title="'.get_string('delete').'">
                <img src="'.$CFG->pixpath.'/t/delete.gif" alt="'.get_string('delete').'" />
                </a>';
        }

        $out = ob_get_contents();

        ob_end_clean();

        return $out;
    }


    /**
     * Gets history of a plan
     *
     * @return object
     */
    function get_history($orderby='timemodified') {
        return get_records('dp_plan_history', 'planid', $this->id, $orderby);
    }

    /**
     * Return a string containing the specified user's role in this plan
     *
     * This currently returns the first role that the user has, although
     * it would be easy to modify to return an array of all matched roles.
     *
     * @param integer $userid ID of the user to find the role of
     *                        If null uses current user's ID
     * @return string Name of the user's role within the current plan
     */
    function get_user_role($userid=null) {

        global $DP_AVAILABLE_ROLES;

        // loop through available roles
        foreach ($DP_AVAILABLE_ROLES as $role) {
            // call a method on each one to determine if user has that role
            if ($hasrole = $this->get_role($role)->user_has_role($userid)) {
                // return the name of the first role to match
                // could change to return an array of all matches
                return $hasrole;
            }
        }

        // no roles matched
        return false;
    }


    /**
     * Return true if the user of this plan has the specified role
     *
     * Typically the user of the plan is the current user, unless a different
     * userid was specified via the viewas parameter when the plan instance
     * was created.
     *
     * This method makes use of $this->role, which is populated by
     * {@link development_plan::get_user_role()} when a new plan is instantiated
     *
     * @param string $role Name of the role to check for
     * @return boolean True if the user has the role specified
     */
    function user_has_role($role) {
        // support array of roles in case we want to allow
        // a user to have multiple roles at some point
        if(is_array($this->role)) {
            if(in_array($role, $this->role)) {
                return true;
            }
        } else {
            if($role == $this->role) {
                return true;
            }
        }
        return false;
    }


    /**
     * Returns all assigned items to components
     *
     * Optionally, filtered by status
     *
     * @access  public
     * @param   mixed   $approved   (optional)
     * @return  array
     */
    public function get_assigned_items($approved = null) {
        $out = array();

        // Get any pending items for each component
        foreach ($this->get_components() as $name => $component) {
            // Ignore if disabled
            if (!$component->get_setting('enabled')) {
                continue;
            }

            $items = $component->get_assigned_items($approved);

            // Ignore if no items
            if (empty($items)) {
                continue;
            }

            $out[$name] = $items;
        }

        return $out;
    }


    /**
     * Returns all unapproved items assigned to components
     *
     * @access  public
     * @return  array
     */
    public function get_unapproved_items() {
        return $this->get_assigned_items(
            array(
                DP_APPROVAL_DECLINED,
//                DP_APPROVAL_APPROVED,
                DP_APPROVAL_UNAPPROVED
            )
        );
    }


    /**
     * Returns all pending items assigned to components
     *
     * @access  public
     * @return  array
     */
    public function get_pending_items() {
        return $this->get_assigned_items(
            array(
                DP_APPROVAL_REQUESTED
            )
        );
    }


    /**
     * Check if the plan has any pending items
     *
     * @access  public
     * @param   array       $pendinglist    (optional)
     * @param   boolean     $onlyapprovable Only check approvable items
     * @param   boolean     $returnapprovable   Return array of approvable items
     * @return  boolean|array
     */
    public function has_pending_items($pendinglist=null, $onlyapprovable=false, $returnapprovable=false) {

        $components = $this->get_components();

        // Get the pending items, if it hasn't been passed to the method
        if (!isset($pendinglist)) {
            $pendinglist = $this->get_pending_items();
        }

        // See if any component has any pending items
        foreach ($components as $componentname => $component) {
            // Skip if empty
            if (empty($pendinglist[$componentname])) {
                continue;
            }

            // Not checking for approvable items?
            if (!$onlyapprovable) {
                return true;
            }

            // Check if approvable
            $canapprove = $component->get_setting("update{$componentname}") == DP_PERMISSION_APPROVE;

            // Returning boolean?
            if (!$returnapprovable && $canapprove) {
                return true;
            }

            // Returning array but can't approve this component
            if ($returnapprovable && !$canapprove) {
                // Remove component from array
                unset($pendinglist[$componentname]);
            }
        }

        if ($returnapprovable && !empty($pendinglist)) {
            return $pendinglist;
        }

        return false;
    }


    /**
     * Given a pair of id/component pairs, returns them in a correctly sorted array
     *
     * @param   string  $component1     Component name of the first item
     * @param   int     $itemid1        Assignment ID of the first item
     * @param   string  $component2     Component name of the second item
     * @param   int     $itemid2        Assignment ID of the second item
     *
     * @return array or false Array of arrays containing the items sorted by component name in the form:
     *  array(
     *    [0] => array ('id' => [firstid], 'component' => [firsttype]),
     *    [1] => array ('id' => [secondid], 'component' => [secondtype]),
     *  )
     *
     *  The array's elements will be sorted by component name, so [firsttype] will alphabetically
     *  preceed [secondtype].
     *
     *  Returns false if you provide the same component type for both items, as linked items
     *  must be of different types
     *
     */
    function get_relation_array($component1, $itemid1, $component2, $itemid2) {
        $unsorted = array(
            array(
                'id' => $itemid1,
                'component' => $component1,
            ),
            array(
                'id' => $itemid2,
                'component' => $component2,
            ),
        );

        $cmp = strcmp($unsorted[0]['component'], $unsorted[1]['component']);
        if ($cmp < 0) {
            // items in correct order already
            return $unsorted;
        } else if ($cmp > 0) {
            // reverse array order
            return array_reverse($unsorted);
        } else {
            // items are the same, not supported
            return false;
        }
    }


    /**
     * Adds a relation between two plan items
     *
     * This method checks if the relation is already set and returns the existing relations ID if found
     *
     * @param   string  $component1     Component name of the first item
     * @param   int     $itemid1        Assignment ID of the first item
     * @param   string  $component2     Component name of the second item
     * @param   int     $itemid2        Assignment ID of the second item
     * @param   string  $mandatory      Which, if any component is mandatory? (optional)
     *
     * @return integer or false ID of the new relation, or the existing relation, or false on failure
     */
    function add_component_relation($component1, $itemid1, $component2, $itemid2, $mandatory = '') {
        $items = $this->get_relation_array($component1, $itemid1, $component2, $itemid2);

        // Couldn't generate items, probably because item 1 and item 2 have same component type
        if ($items === false) {
            return false;
        }

        // See if the relation already exists
        $existingrelation = get_record_select(
            'dp_plan_component_relation',
            "
                itemid1 = {$items[0]['id']}
            AND component1 = '{$items[0]['component']}'
            AND itemid2 = {$items[1]['id']}
            AND component2 = '{$items[1]['component']}'
        ");

        // Relation already exists and mandatory value hasn't changed, return the relation ID
        if ($existingrelation && $existingrelation->mandatory == $mandatory) {
            return $existingrelation->id;
        }

        // Otherwise create/update the relation, returning the new ID
        $todb = new object();
        $todb->itemid1 = $items[0]['id'];
        $todb->component1 = $items[0]['component'];
        $todb->itemid2 = $items[1]['id'];
        $todb->component2 = $items[1]['component'];
        $todb->mandatory = $mandatory;

        // If there but diff mandatory, update
        if ($existingrelation) {
            $todb->id = $existingrelation->id;
            return update_record('dp_plan_component_relation', $todb);
        } else {
            return insert_record('dp_plan_component_relation', $todb);
        }
    }


    /**
     * Display plan message box
     *
     * Generally includes messages about the plan's status as a whole
     *
     * @access  public
     * @return  string
     */
    public function display_plan_message_box() {
        $unapproved = ($this->status == DP_PLAN_STATUS_UNAPPROVED);
        $completed = ($this->status == DP_PLAN_STATUS_COMPLETE);
        $viewingasmanager = $this->role == 'manager';
        $pending = $this->get_pending_items();
        $haspendingitems = $this->has_pending_items($pending);
        $canapprovepending = $this->has_pending_items($pending, true);
        $unapproveditems = $this->get_unapproved_items();
        $hasunapproveditems = !empty($unapproveditems);

        $canapproveplan = (in_array($this->get_setting('approve'), array(DP_PERMISSION_APPROVE, DP_PERMISSION_ALLOW)));

        $message = '';
        if ($viewingasmanager) {
            $message .= $this->display_viewing_users_plan($this->userid);
        }

        if ($completed) {
            $message .= $this->display_completed_plan_message();
            $style = 'plan_box_completed';
        } else {
            if ($canapprovepending || $canapproveplan) {
                $style = 'plan_box_action';
            } else {
                $style = 'plan_box_plain';
            }

            if (!$viewingasmanager && $hasunapproveditems) {
                $message .= $this->display_unapproved_items($unapproveditems);
            }

            if ($unapproved) {
                if ($haspendingitems) {
                    if ($canapprovepending) {
                        $message .= $this->display_pending_items($pending);
                    } else if ($canapproveplan) {
                        $message .= $this->display_unapproved_plan_message();
                    } else {
                        $message .= $this->display_pending_items($pending);
                    }
                }

                $message .= $this->display_unapproved_plan_message();
            } else {
                if ($haspendingitems) {
                    $message .= $this->display_pending_items($pending);
                } else {
                    // nothing to report (no message)
                }
            }
        }

        if ($message == '') {
            return '<div class="plan_box" style="display:none;"></div>';
        }
        return '<div class="plan_box '.$style.' clearfix">'.$message.'</div>';
    }


    /**
     * Display completed plan message
     *
     * @return string
     */
    function display_completed_plan_message() {
        global $CFG;
        $sql = "SELECT * FROM {$CFG->prefix}dp_plan_history WHERE planid={$this->id} ORDER BY timemodified DESC";
        if ($history = get_record_sql($sql, true)) {
            switch ($history->reason) {
            case DP_PLAN_REASON_MANUAL_COMPLETE:
                $message = get_string('plancompleted', 'local_plan');
                break;

            case DP_PLAN_REASON_AUTO_COMPLETE_ITEMS:
                $message = get_string('planautocompleteditems', 'local_plan');
                break;

            case DP_PLAN_REASON_AUTO_COMPLETE_DATE:
                $message = get_string('planautocompleteddate', 'local_plan');
                break;
            }

            if (!$message) {
                $message = get_string('plancompleted', 'local_plan');
            }

            $extramessage = '';
            if ($this->get_setting('completereactivate') == DP_PERMISSION_ALLOW) {
                $url = "{$CFG->wwwroot}/totara/plan/action.php?id={$this->id}&amp;reactivate=1&amp;sesskey=".sesskey();
                $extramessage = '<p>' . get_string('reactivateplantext', 'local_plan', $url) . '</p>';
            }
            return '<p>' . $message . '</p>'. $extramessage;
        }
    }


    /**
     * Display unapproved plan message
     *
     * @return string
     */
    function display_unapproved_plan_message() {
        global $CFG;

        $canapproveplan = (in_array($this->get_setting('approve'),  array(DP_PERMISSION_APPROVE, DP_PERMISSION_ALLOW)));
        $canrequestapproval = ($this->get_setting('approve') == DP_PERMISSION_REQUEST);
        $out = '';

        $out .= "<form action=\"{$CFG->wwwroot}/totara/plan/action.php\" method=\"POST\" class=\"approvalform\">";
        $out .= "<input type=\"hidden\" name=\"id\" value=\"{$this->id}\"/>";
        $out .= "<input type=\"hidden\" name=\"sesskey\" value=\"".sesskey()."\"/>";
        $out .= '<table width="100%" border="0"><tr>';
        $out .= '<td class="c0">'.get_string('plannotapproved', 'local_plan').'</td>';

        if($canapproveplan) {
            $out .= '<td class="c1"><input type="submit" name="approve" value="' . get_string('approve', 'local_plan') . '" /> &nbsp; ';
            $out .= '<input type="submit" name="decline" value="' . get_string('decline', 'local_plan') . '" /></td>';
        } elseif ($canrequestapproval) {
            $out .= '<td class="c1"><input type="submit" name="approvalrequest" value="' . get_string('sendapprovalrequest', 'local_plan') . '" /> &nbsp; ';
        }

        $out .= '</tr></table></form>';

        return $out;
    }


    /**
     * Display pending items list
     *
     * @param object $pendinglist
     * @return string
     */
    function display_pending_items($pendinglist=null) {
        global $CFG, $DP_AVAILABLE_COMPONENTS;

        // If this is the pending review page, do not show list of items
        if ($this->reviewing_pending) {
            return '';
        }

        // get the pending items, if it hasn't been passed to the method
        if(!isset($pendinglist)) {
            $pendinglist = $this->get_pending_items();
        }

        $list = '';
        $listcount = 0;
        $itemscount = 0;

        $approval = false;

        foreach($DP_AVAILABLE_COMPONENTS as $componentname) {
            if (!$component = $this->get_component($componentname)) {
                continue;
            }

            $canapprove = $component->get_setting('update'.$component->component) == DP_PERMISSION_APPROVE;
            $enabled = $component->get_setting('enabled');

            if ($enabled && !empty($pendinglist[$component->component])) {
                $a = new object();
                $a->planid = $this->id;
                $a->number = count($pendinglist[$component->component]);
                $itemscount += $a->number;
                $a->component = $component->component;
                $name = $a->component;
                // determine plurality
                $langkey = $name . ($a->number > 1 ? 'plural' : '');
                $a->name = (get_string($langkey, 'local_plan') ? get_string($langkey, 'local_plan') : $name);
                $a->link = $component->get_url();
                $list .= '<li>' . get_string('xitemspending', 'local_plan', $a) . '</li>';
                $listcount++;
            }
            $approval = $approval || $canapprove;
        }

        $descriptor = $approval ? 'thefollowingitemsrequireyourapproval' : 'thefollowingitemsarepending';

        // only print if there are pending items
        $out = '';
        if($listcount) {
            $descriptor .= ($itemscount > 1 ? '_p' : '_s');
            $out .= '<div class="plan_box_wrap"><p>' . get_string($descriptor, 'local_plan'). '</p>';
            $out .= '<ul>' . $list . '</ul></div>';
        }

        return $out;
    }


    /**
     * Display status of unapproved items
     *
     * @access  public
     * @param   array   $unapproved
     * @return  string
     */
    public function display_unapproved_items($unapproved) {
        global $CFG;

        $out = '';
        $out .= '<ul>';

        // Show list of items
        $totalitems = 0;
        foreach ($unapproved as $component => $items) {

            $comp = $this->get_component($component);

            $a = new object();
            $a->uri = $comp->get_url();
            $a->number = count($items);
            $totalitems += $a->number;
            $a->name = $component;
            // determine plurality
            $langkey = $a->name . ($a->number > 1 ? 'plural' : '');
            $a->name = get_string($langkey, 'local_plan');
            $out .= '<li>'.get_string('xitemsunapproved', 'local_plan', $a).'</li>';
        }

        // put the heading on now we know how many
        $out = '<div class="plan_box_wrap"><p>'.get_string(($totalitems > 1 ? 'planhasunapproveditems' : 'planhasunapproveditem'), 'local_plan').'</p>'.$out;
        $out .= '</ul></div>';

        // Show request button if plan is active
        if ($this->status == DP_PLAN_STATUS_APPROVED) {
            $out .= "<form action=\"{$CFG->wwwroot}/totara/plan/action.php\" method=\"POST\">";
            $out .= "<input type=\"hidden\" name=\"id\" value=\"{$this->id}\"/>";
            $out .= "<input type=\"hidden\" name=\"sesskey\" value=\"".sesskey()."\"/>";
            $out .= '<input type="submit" name="approvalrequest" value="'.get_string('sendapprovalrequest', 'local_plan').'" />';
            $out .= '</form>';
        }

        return $out;
    }


    /**
     * Display the viewing users plan
     *
     * @access public
     * @param int $userid
     * @return string
     */
    static public function display_viewing_users_plan($userid) {
        global $CFG;
        $user = get_record('user', 'id', $userid);
        if(!$user) {
            return '';
        }
        $out = '';
        $out .= '<table border="0" width="100%"><tr><td width="50">';
        $out .= print_user_picture($user, 1, null, 0, true);
        $out .= '</td><td>';
        $a = new object();
        $a->name = fullname($user);
        $a->userid = $userid;
        $a->site = $CFG->wwwroot;
        $out .= get_string('youareviewingxsplan', 'local_plan', $a);
        $out .= '</td></tr></table>';
        return $out;
    }


    /**
     * Delete the plan and all of its relevant data
     *
     * @return boolean
     */
    function delete() {
        global $CFG, $DP_AVAILABLE_COMPONENTS, $USER;

        require_once("{$CFG->libdir}/ddllib.php");

        begin_sql();

        // Delete plan
        if (!delete_records('dp_plan', 'id', $this->id)) {
            rollback_sql();
            return false;
        }
        //Delete plan history
        if (!delete_records('dp_plan_history', 'planid', $this->id)) {
            rollback_sql();
            return false;
        }
        // Delete related components
        foreach ($DP_AVAILABLE_COMPONENTS as $c) {
            $itemids = array();
            $table = new XMLDBTable("dp_plan_{$c}");
            if (table_exists($table)) {
                $field = new XMLDBField('planid');
                if (field_exists($table, $field)) {
                    // Get record ids for later use in deletion of assign tables
                    $ids = get_records($table->name, 'planid', $this->id, '', 'id');
                    if (!delete_records($table->name, 'planid', $this->id)) {
                        rollback_sql();
                        return false;
                    }
                    $table = new XMLDBTable("dp_plan_{$c}_assign");
                    if (table_exists($table)) {
                        foreach ($ids as $i) {
                            $itemids = array_merge($itemids, get_records($table->name, "{$c}id", $i, '', 'id'));
                            if (!delete_records($table->name, "{$c}id", $i)) {
                                rollback_sql();
                                return false;
                            }
                        }
                    }
                }
            } else {
                $table = new XMLDBTable("dp_plan_{$c}_assign");
                if (table_exists($table)) {
                    $itemids = get_records($table->name, 'planid', $this->id, '', 'id');
                    if (!delete_records($table->name, 'planid', $this->id)) {
                        rollback_sql();
                        return false;
                    }
                }
            }
            if (!empty($itemids)) {
                // Delete component relations
                foreach ($itemids as $id=>$value) {
                    if (!delete_records('dp_plan_component_relation', 'itemid1', $id) || !delete_records('dp_plan_component_relation', 'itemid2', $id)) {
                        rollback_sql();
                        return false;
                    }
                }
            }
        }

        commit_sql();
        return true;
    }


    /**
     * Change plan's status
     *
     * @access  public
     * @param   integer $status
     * @param       $autocomplete
     * @return  bool
     */
    public function set_status($status, $reason=DP_PLAN_REASON_MANUAL_APPROVE) {
        global $USER;

        $todb = new stdClass;
        $todb->id = $this->id;
        $todb->status = $status;

        begin_sql();

        // Handle some status triggers
        switch ($status) {
            case DP_PLAN_STATUS_APPROVED:
                // Set the plan startdate to the approval time if not being reactivate
                if ($reason != DP_PLAN_REASON_MANUAL_REACTIVATE) {
                    $todb->startdate = time();
                }
                plan_activate_plan($this);
                break;
            case DP_PLAN_STATUS_COMPLETE:
                if ($assigned = $this->get_component('competency')->get_assigned_items()) {
                    // Set competency snapshots
                    foreach ($assigned as $a) {
                        $snap = new stdClass;
                        $snap->id = $a->id;
                        $snap->scalevalueid = !empty($a->profscalevalueid) ? $a->profscalevalueid : 0;
                        if (!update_record('dp_plan_competency_assign', $snap)) {
                            return false;
                        }
                    }
                }
                if ($assigned = $this->get_component('course')->get_assigned_items()) {
                    // Set course completion snapshots
                    foreach ($assigned as $a) {
                        $snap = new stdClass;
                        $snap->id = $a->id;
                        $snap->completionstatus = !empty($a->coursecompletion) ? $a->coursecompletion : null;
                        if (!update_record('dp_plan_course_assign', $snap)) {
                            return false;
                        }
                    }
                }
                $plantodb = new stdClass;
                $plantodb->id = $this->id;
                $plantodb->timecompleted = time();
                if (!update_record('dp_plan', $plantodb)) {
                    return false;
                }
                break;
            default:
                break;
        }

        if (update_record('dp_plan', $todb)) {
            // Update plan history
            $todb = new stdClass;
            $todb->planid = $this->id;
            $todb->status = $status;
            $todb->reason = $reason;
            $todb->timemodified = time();
            $todb->usermodified = $USER->id;

            if (!insert_record('dp_plan_history', $todb)) {
                rollback_sql();
                return false;
            }
        } else {
            return false;
        }

        commit_sql();

        if ($status == DP_PLAN_STATUS_APPROVED) {
            add_to_log(SITEID, 'plan', 'approved', "view.php?id={$this->id}", $this->name);
        }

        return true;
    }


    /**
     * Determine the manager for the user of this Plan
     *
     * @return string
     */
    function get_manager() {
        return totara_get_manager($this->userid);
    }


    /**
     * Send a task to the manager when a learner requests a plan approval
     * @global <type> $USER
     * @global object $CFG
     */
    function send_manager_plan_approval_request() {
        global $USER, $CFG;

        $manager = totara_get_manager($this->userid);
        $learner = get_record('user','id',$this->userid);
        if ($manager && $learner) {
            // do the IDP Plan workflow event
            $data = array();
            $data['userid'] = $this->userid;
            $data['planid'] = $this->id;

            $event = new tm_task_eventdata($manager, 'plan', $data, $data);
            $event->userfrom = $learner;
            $event->contexturl = $this->get_display_url();
            $event->contexturlname = $this->name;
            $event->icon = 'learningplan-request';

            $a = new stdClass;
            $a->learner = fullname($learner);
            $a->plan = s($this->name);
            $event->subject =      get_string('plan-request-manager-short', 'local_plan', $a, $manager->lang);
            $event->fullmessage =  get_string('plan-request-manager-long', 'local_plan', $a, $manager->lang);
            $event->acceptbutton = get_string('approve', 'local_plan', null, $manager->lang).' '.get_string('plan', 'local_plan', null, $manager->lang);
            $event->accepttext =   get_string('approveplantext', 'local_plan', null, $manager->lang);
            $event->rejectbutton = get_string('decline', 'local_plan', null, $manager->lang).' '.get_string('plan', 'local_plan', null, $manager->lang);
            $event->rejecttext =   get_string('declineplantext', 'local_plan', null, $manager->lang);
            $event->infobutton =   get_string('review', 'local_plan', null, $manager->lang).' '. get_string('plan', 'local_plan', null, $manager->lang);
            $event->infotext =     get_string('reviewplantext', 'local_plan', null, $manager->lang);
            $event->data = $data;

            tm_workflow_send($event);

            // Send alert to learner also
            $this->send_alert(true, 'learningplan-request', 'plan-request-learner-short', 'plan-request-learner-long');
        }
    }


    /**
     * Send a task to the manager when a learner requests item's approval
     *
     * @access  public
     * @global  object  $USER
     * @global  object  $CFG
     * @param   array   $unapproved
     * @return  void
     */
    public function send_manager_item_approval_request($unapproved) {
        global $USER, $CFG;

        $manager = totara_get_manager($this->userid);
        $learner = get_record('user','id',$this->userid);

        if (!$manager || !$learner) {
            print_error('error:couldnotloadusers', 'local_plan');
            die();
        }

        // Message data
        $message_data = array();
        $total_items = 0;

        $data = array();
        $data['userid'] = $this->userid;
        $data['planid'] = $this->id;

        // Change items to requested status
        // Loop through components, generating message
        foreach ($unapproved as $component => $items) {
            $comp = $this->get_component($component);
            $items = $comp->make_items_requested($items);

            // Generate message
            if ($items) {
                $total_items += count($items);
                $message_data[] = count($items).' '. get_string($comp->component, 'local_plan', null, $manager->lang);
            }
        }

        $event = new tm_task_eventdata($manager, 'plan', $data, $data);
        $event->userfrom = $learner;
        $event->contexturl = "{$CFG->wwwroot}/totara/plan/approve.php?id={$this->id}";
        $event->contexturlname = $this->name;
        $event->icon = 'learningplan-request';

        $a = new stdClass;
        $a->learner = fullname($learner);
        $a->plan = s($this->name);
        $a->data = '<ul><li>'.implode($message_data, '</li><li>').'</li></ul>';
        $event->subject = get_string('item-request-manager-short', 'local_plan', $a, $manager->lang);
        $event->fullmessage = get_string('item-request-manager-long', 'local_plan', $a, $manager->lang);
        unset($event->acceptbutton);
        unset($event->onaccept);
        unset($event->rejectbutton);
        unset($event->onreject);
        $event->infobutton = get_string('review', 'local_plan', null, $manager->lang).' '.get_string('items', 'local_plan', null, $manager->lang);
        $event->infotext = get_string('reviewitemstext', 'local_plan', null, $manager->lang);
        $event->data = $data;

        tm_workflow_send($event);
    }


    /**
     * Send an alert relating to this plan
     *
     * @param boolean $tolearner To the learner if true, otherwise to the manager
     * @param string $icon filename of icon (in theme/totara/pix/msgicons/)
     * @param string $subjectstring lang string in local_plan
     * @param string $fullmessagestring lang string in local_plan
     * @return boolean
     */
    public function send_alert( $tolearner, $icon, $subjectstring, $fullmessagestring ){
        global $CFG;
        $manager = totara_get_manager($this->userid);
        $learner = get_record('user','id',$this->userid);
        if ($learner && $manager){
            require_once($CFG->dirroot . '/totara/message/eventdata.class.php');
            require_once($CFG->dirroot . '/totara/message/messagelib.php');
            if ($tolearner){
                $userto = $learner;
                $userfrom = $manager;
                $roleid = $CFG->learnerroleid;
            } else {
                $userto = $manager;
                $userfrom = $learner;
                $roleid = $CFG->managerroleid;
            }
            $event = new tm_alert_eventdata($userto);
            $event->userfrom = $userfrom;
            $event->contexturl = $this->get_display_url();
            $event->contexturlname = $this->name;
            $event->icon = $icon;

            $a = new stdClass();
            $a->plan = $this->name;
            $a->manager = fullname($manager);
            $a->learner = fullname($learner);
            $event->subject = get_string($subjectstring,'local_plan',$a, $userto->lang);
            $event->fullmessage = get_string($fullmessagestring,'local_plan',$a, $userto->lang);

            return tm_alert_send($event);
        } else {
            return false;
        }
    }


    /**
     * Send approved alerts
     *
     * @global $USER
     * @global $CFG
     * @return void
     */
    function send_approved_alert() {
        global $USER, $CFG;
        require_once($CFG->dirroot.'/totara/totara_msg/messagelib.php');

        $userto = get_record('user', 'id', $this->userid);
        $userfrom = get_record('user', 'id', $USER->id);

        $event = new stdClass;
        $event->userfrom = $userfrom;
        $event->userto = $userto;
        $event->icon = 'learningplan-approve';
        $event->contexturl = $CFG->wwwroot.'/totara/plan/view.php?id='.$this->id;
        $event->fullmessage = get_string('planapproved', 'local_plan', $this->name, $userto->lang);
        tm_alert_send($event);
    }


    /**
     * Send declined alerts
     *
     * @global $USER
     * @global $CFG
     * @return void
     */
    function send_declined_alert() {
        global $USER, $CFG;
        require_once($CFG->dirroot.'/totara/totara_msg/messagelib.php');

        $userto = get_record('user', 'id', $this->userid);
        $userfrom = get_record('user', 'id', $USER->id);

        $event = new stdClass;
        $event->userfrom = $userfrom;
        $event->userto = $userto;
        $event->icon = 'learningplan-decline';
        $event->contexturl = $CFG->wwwroot.'/totara/plan/view.php?id='.$this->id;
        $event->fullmessage = format_string(get_string('plandeclined', 'local_plan', $this->name, $userto->lang));
        tm_alert_send($event);
    }


    /**
     * Send completion alerts
     *
     * @global $USER
     * @global $CFG
     * @return void
     */
    function send_completion_alert() {
        global $USER, $CFG;
        require_once($CFG->dirroot.'/totara/totara_msg/messagelib.php');
        $learner = get_record('user', 'id', $this->userid);

        // Send alert to manager
        // But don't send it if they just manually performed
        // the completion
        $manager = totara_get_manager($this->userid);
        if ($manager && $manager->id != $USER->id) {
            $event = new stdClass();
            $event->userto = $manager;
            $event->userfrom = $learner;
            $event->icon = 'learningplan-complete';
            $event->contexturl = $CFG->wwwroot.'/totara/plan/view.php?id='.$this->id;
            $a = new stdClass();
            $a->learner = fullname($learner);
            $a->plan = $this->name;
            $event->subject = get_string('plan-complete-manager-short','local_plan',$a, $manager->lang);
            $event->fullmessage = get_string('plan-complete-manager-long','local_plan',$a, $manager->lang);
            tm_alert_send($event);
        }

        // Send alert to user
        $event = new stdClass();
        $event->userto = $learner;
        $event->icon = 'learningplan-complete';
        $event->contexturl = $CFG->wwwroot.'/totara/plan/view.php?id='.$this->id;
        $event->fullmessage = format_text(get_string('plancompletesuccess', 'local_plan', $this->name, $learner->lang));
        tm_alert_send($event);
    }

    /**
     * Returns the URL for the page to view this plan
     * @global object $CFG
     * @return string
     */
    public function get_display_url(){
        global $CFG;
        if (record_exists('dp_plan', 'id', $this->id)) {
            return "{$CFG->wwwroot}/totara/plan/view.php?id={$this->id}";
        } else {
            //If plan doesnt exist show plan index page for that user
            return "{$CFG->wwwroot}/totara/plan/index.php?userid={$this->userid}";
        }

    }


    /**
     * Display plan tabs
     *
     * @access  public
     * @param   string  $currenttab Currently selected tab's key
     * @return  string
     */
    public function display_tabs($currenttab) {
        global $CFG;

        $tabs = array();
        $row = array();
        $activated = array();
        $inactive = array();

        // Overview tab
        $row[] = new tabobject(
                'plan',
                "{$CFG->wwwroot}/totara/plan/view.php?id={$this->id}",
                get_string('overview', 'local_plan')
        );

        // get active components in correct order
        $components = $this->get_components();

        if ($components) {
            foreach ($components as $component) {

                $row[] = new tabobject(
                    $component->component,
                    $component->get_url(),
                    $componentname = get_string("{$component->component}plural", 'local_plan')
                );
            }
        }

        // requested items tabs
        if ($pitems = $this->num_pendingitems()) {
            $row[] = new tabobject(
                'pendingitems',
                "{$CFG->wwwroot}/totara/plan/approve.php?id={$this->id}",
                get_string('pendingitems', 'local_plan').' ('.$pitems.')'
            );
        }

        $tabs[] = $row;
        $activated[] = $currenttab;

        return print_tabs($tabs, $currenttab, $inactive, $activated, true);
    }


    /**
     * Prints plan header
     *
     * @access  public
     * @param   string  $currenttab Current tab key
     * @param   array   $navlinks   Additional navlinks (optional)
     * @return  void
     */
    public function print_header($currenttab, $navlinks = array(), $printinstructions=true) {
        global $CFG;
        require("{$CFG->dirroot}/totara/plan/header.php");
    }


    /**
     * Reactivates a completed plan
     *
     * @param  int $enddate When reactivating a plan a new enddate for the plan can be optionally set
     * @access public
     * @return bool
     */
    public function reactivate_plan($enddate=null) {
        global $USER;

        begin_sql();

        $plan_todb = new stdClass;
        $plan_todb->id = $this->id;
        $plan_todb->timecompleted = null;
        if (!empty($enddate)) {
            $plan_todb->enddate = $enddate;
        }

        if (!update_record('dp_plan', $plan_todb)) {
            rollback_sql();
            return false;
        }

        $this->set_status(DP_PLAN_STATUS_APPROVED, DP_PLAN_REASON_MANUAL_REACTIVATE);
        $components = $this->get_components();
        // Reactivates items for all components
        foreach ($components as $component) {
            if (!$component->reactivate_items()) {
                rollback_sql();
                return false;
            }
        }

        // Send alerts to notify of reactivation
        $manager = totara_get_manager($this->userid);
        if ($manager && $manager->id != $USER->id) {
            $subjectstring = 'plan-reactivate-manager-short';
            $fullmessagestring = 'plan-reactivate-manager-long';
        } else {
            $subjectstring = 'plan-reactivate-learner-short';
            $fullmessagestring = 'plan-reactivate-learner-long';
        }
        $this->send_alert(true, 'learningplan-regular', $subjectstring, $fullmessagestring);

        commit_sql();
        return true;

    }

    /**
     * Returns true if all items in a plan are complete
     *
     * @return bool $complete returns true if all items in plan are completed
     */
    public function is_plan_complete(){
        $complete = true;
        $components = $this->get_components();
        foreach ($components as $component) {
            $complete = $complete && $component->items_all_complete();
        }
        return $complete;
    }
}


class PlanException extends Exception { }