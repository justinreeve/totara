<?php

require_once('../../../../config.php');
require_once($CFG->dirroot.'/hierarchy/type/position/lib.php');
require_once($CFG->dirroot.'/local/js/setup.php');
require_once('competency_evidence_form.php');
///
/// Setup / loading data
///

$userid = required_param('userid', PARAM_INT);
$returnurl = optional_param('returnurl', $CFG->wwwroot, PARAM_TEXT);
$proficiency = optional_param('proficiency', null, PARAM_INT);
$s = optional_param('s', null, PARAM_TEXT);

if($u = get_record('user','id',$userid)) {
    $toform = new object();
    $toform->user = $u->firstname.' '.$u->lastname;
} else {
    error('error:usernotfound','local');
}

// only redirect back if we are sure that's where they came from
if($s != sesskey()) {
    $returnurl = $CFG->wwwroot;
}

// Check perms
$sitecontext = get_context_instance(CONTEXT_SYSTEM);
require_capability('moodle/local:updatecompetency', $sitecontext);

$mform =& new mitms_competency_evidence_form(null, compact('id','userid','user','returnurl','s'));
if ($mform->is_cancelled()) {
    redirect($returnurl);
}
if($fromform = $mform->get_data()) { // Form submitted
    if (empty($fromform->submitbutton)) {
        print_error('error:unknownbuttonclicked', 'local', $returnurl);
    }
    $todb = new object();
    $todb->userid = $fromform->userid;
    $todb->competencyid = $fromform->competencyid;
    $todb->positionid = $fromform->positionid != 0 ? $fromform->positionid : null;
    $todb->organisationid = $fromform->organisationid != 0 ? $fromform->organisationid : null;
    $todb->assessorid = $fromform->assessorid != 0 ? $fromform->assessorid : null;
    $todb->assessorname = $fromform->assessorname;
    $todb->assessmenttype = $fromform->assessmenttype;
    // proficiency not obtained by get_data() because form element is populated
    // via javascript after page load. Get via optional POST parameter instead.
    $todb->proficiency = $proficiency;
    $todb->timecreated = time();
    $todb->timemodified = $fromform->timemodified;
    if(insert_record('competency_evidence',$todb)) {
        redirect($returnurl);
    } else {
        redirect($returnurl, get_string('recordnotreated','local'));
    }

} else {
    $mform->set_data($toform);
}

///
/// Display page
///

// Setup custom javascript
setup_lightbox(array(MBE_JS_TREEVIEW, MBE_JS_ADVANCED));
require_js(
    array(
        $CFG->wwwroot.'/local/js/lib/ui.datepicker.js',
        $CFG->wwwroot.'/local/js/position.user.js.php',
    )
);

$CFG->stylesheets[] = $CFG->wwwroot.'/local/js/lib/ui-lightness/jquery-ui-1.7.2.custom.css';


print_header();

print '<h2>'.get_string('addcompetencyevidence', 'local').'</h2>';

$mform->display();

print_footer();
