<?php
namespace mod_exportanotas\task;

defined('MOODLE_INTERNAL') || die();

class export_grades_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('exportgrades', 'mod_exportanotas');
    }

    public function execute() {

    }
}