<?php
/*
 * This file is part of Totara LMS
 *
 * Copyright (C) 2010, 2011 Totara Learning Solutions LTD
 * Copyright (C) 1999 onwards Martin Dougiamas
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Alastair Munro <alastair@catalyst.net.nz>
 * @author Simon Coggins <simonc@catalyst.net.nz>
 * @package totara
 * @subpackage plan
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once('editvalue_form.php');
require_once('lib.php');

///
/// Setup / loading data
///

$id = optional_param('id', 0, PARAM_INT); // Scale value id; 0 if inserting
$priorityscaleid = optional_param('priorityscaleid', PARAM_INT); // Priority scale id

// Make sure we have at least one or the other
if (!$id && !$priorityscaleid) {
    error(get_string('error:incorrectparameters', 'local_plan'));
}

// Page setup and check permissions
admin_externalpage_setup('priorityscales');

$sitecontext = get_context_instance(CONTEXT_SYSTEM);

require_capability('totara/plan:managepriorityscales', $sitecontext);
if ($id == 0) {
    // Creating new scale value

    $value = new stdClass();
    $value->id = 0;
    $value->priorityscaleid = $priorityscaleid;
    $value->sortorder = get_field('dp_priority_scale_value', 'MAX(sortorder) + 1', 'priorityscaleid', $value->priorityscaleid);
    if (!$value->sortorder) {
        $value->sortorder = 1;
    }
} else {
    // Editing scale value

    if (!$value = get_record('dp_priority_scale_value', 'id', $id)) {
        error(get_string('error:priorityscalevalueidincorrect', 'local_plan'));
    }
}

if (!$scale = get_record('dp_priority_scale', 'id', $value->priorityscaleid)) {
    error(get_string('error:priorityscaleidincorrect', 'local_plan'));
}

$scale_used = dp_priority_scale_is_used($scale->id);

// Save priority scale name for display in the form
$value->scalename = format_string($scale->name);

// check scale isn't being used when adding new scale values
if($value->id == 0 && $scale_used) {
    error('You cannot add a scale value to a scale that is in use.');
}

///
/// Display page
///

// Create form
$valueform = new dp_priority_scale_value_edit_form();
$valueform->set_data($value);

// cancelled
if ($valueform->is_cancelled()) {

    redirect("$CFG->wwwroot/totara/plan/priorityscales/view.php?id={$value->priorityscaleid}");

// Update data
} else if ($valuenew = $valueform->get_data()) {

    $valuenew->timemodified = time();
    $valuenew->usermodified = $USER->id;

    if (!strlen($valuenew->numericscore)) {
        $valuenew->numericscore = null;
    }

    // Save
    // New priority scale value
    if ($valuenew->id == 0) {
        unset($valuenew->id);

        if ($valuenew->id = insert_record('dp_priority_scale_value', $valuenew)) {
            // Log
            add_to_log(SITEID, 'priorities', 'scale value added', "view.php?id={$valuenew->priorityscaleid}");

            totara_set_notification(get_string('priorityscalevalueadded', 'local_plan', format_string(stripslashes($valuenew->name))),
                "$CFG->wwwroot/totara/plan/priorityscales/view.php?id={$valuenew->priorityscaleid}",
                array('class' => 'notifysuccess'));
        } else {
            totara_set_notification(get_string('error:createpriorityvalue', 'local_plan'),
                "$CFG->wwwroot/totara/plan/priorityscales/view.php?id={$priorityscaleid}");
        }

    // Updating priority scale value
    } else {
        if (update_record('dp_priority_scale_value', $valuenew)) {

            // Log
            add_to_log(SITEID, 'priorities', 'scale value updated', "view.php?id={$valuenew->priorityscaleid}");

            totara_set_notification(get_string('priorityscalevalueupdated', 'local_plan', format_string(stripslashes($valuenew->name))),
                "$CFG->wwwroot/totara/plan/priorityscales/view.php?id={$valuenew->priorityscaleid}",
                array('class' => 'notifysuccess'));

        } else {
            totara_set_notification(get_string('error:updatepriorityscalevalue', 'local_plan'),
                "$CFG->wwwroot/totara/plan/priorityscales/view.php?id={$priorityscaleid}");
        }
    }

}

// Display page header
admin_externalpage_print_header();

if ($id == 0) {
    print_heading(get_string('addnewpriorityvalue', 'local_plan'));
} else {
    print_heading(get_string('editpriorityvalue', 'local_plan'));
}

// Display warning if scale is in use
if($scale_used) {
    print_container(get_string('priorityscaleinuse', 'local_plan'), true, 'notifysuccess');
}

$valueform->display();

/// and proper footer
print_footer();