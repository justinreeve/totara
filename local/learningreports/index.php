<?php // $Id$
    require_once('../../config.php');
    require_once($CFG->libdir.'/adminlib.php');
    require_once($CFG->dirroot.'/local/learningreports/learningreportslib.php');
    require_once('learning_reports_forms.php');

    admin_externalpage_setup('learningreports');

    $returnurl = $CFG->wwwroot.'/local/learningreports/index.php';
    // form definition
    $mform =& new learning_reports_new_form();

    // form results check
    if ($mform->is_cancelled()) {
        redirect($returnurl);
    }
    if ($fromform = $mform->get_data()) {

        if(empty($fromform->submitbutton)) {
            print_error('error:unknownbuttonclicked', 'local', $returnurl);
        }
        // create new record here
        $todb = new object();
        $todb->fullname = $fromform->fullname;
        $todb->shortname = $fromform->shortname;
        $todb->source = ($fromform->source != '0') ? $fromform->source : null;
        // create with default columns and queries
        // TODO How to get default data for non existant report
        $todb->columns = serialize(get_source_data($fromform->source,'defaultcolumns'));
        $todb->filters = serialize(get_source_data($fromform->source,'defaultqueries'));
        if(isset($fromform->restriction)) {
            $todb->restriction = ($fromform->restriction != '0') ? $fromform->restriction : null;
        }
        if(insert_record('learning_report',$todb)) {
            redirect($returnurl, get_string('newreportcreated','local'));
        } else {
            redirect($returnurl, get_string('error:couldnotcreatenewreport','local'));
        }
    }

    admin_externalpage_print_header();

    print_heading(get_string('learningreports','local'));

    $reports = get_records('learning_report');
    if($reports) {
    foreach($reports as $report) {
        $row = array();
        $settings = '<a href="'.$CFG->wwwroot.'/local/learningreports/settings.php?id='.$report->id.'">' .
            get_string('settings').'</a>';
        $delete = '<a href="'.$CFG->wwwroot.'/local/learningreports/delete.php?id='.$report->id.'">' .
            get_string('delete').'</a>';
        $row[] = $report->fullname;
        $row[] = $report->shortname;
        $row[] = $report->source;
        $row[] = "$settings &nbsp; $delete";
        $data[] = $row;
    }

    $tableheader = array(get_string('name','local'),
                         get_string('uniquename','local'),
                         get_string('source','local'),
                         get_string('options','local')
                     );

    $reportstable = new object();
    $reportstable->summary = '';
    $reportstable->head = $tableheader;
    $reportstable->data = $data;
    print_table($reportstable);
    } else {
        print "No reports have been created";
    }

    // display mform
    $mform->display();

    admin_externalpage_print_footer();


?>