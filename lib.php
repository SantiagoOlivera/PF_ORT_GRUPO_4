<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Library of interface functions and constants.
 *
 * @package     mod_exportanotas
 * @copyright   2024 Your Name <you@example.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return if the plugin supports $feature.
 *
 * @param string $feature Constant representing the feature.
 * @return true | null True if the feature is supported, null otherwise.
 */
function exportanotas_supports($feature) {
    switch ($feature) {
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the mod_exportanotas into the database.
 *
 * Given an object containing all the necessary data, (defined by the form
 * in mod_form.php) this function will create a new instance and return the id
 * number of the instance.
 *
 * @param object $moduleinstance An object from the form.
 * @param mod_exportanotas_mod_form $mform The form.
 * @return int The id of the newly inserted record.
 */
function exportanotas_add_instance($moduleinstance, $mform = null) {
    global $DB;

    // Insertar la instancia del modulo
    $moduleinstance->timecreated = time();
    $id = $DB->insert_record('exportanotas', $moduleinstance, true);

    // se obtenienen los datos del formulario
    if ($mform) {
        $data = $mform->get_data();

        // se verifica que los datos no estén vacíos y contengan los valores esperados
        if ($data) {
            // se crea la tarea programada con las elecciones del usuario
            $task = new stdClass();
            $task->component = 'mod_exportanotas';
            $task->classname = '\\mod_exportanotas\\task\\exportar_calificaciones_task';

            // Configurar la tarea programada con los valores obtenidos del formulario
            $task->minute = '*';
            $task->hour = '*';
            $task->day = '*';
            $task->month = '*';
            $task->dayofweek = '*';
            $task->nextruntime = 0;
            $task->disabled = 0;
            $task->locked = 0;
            $task->lastduration = 0;
            $task->laststatus = 0;
            $task->lastexecuted = 0;

            // Buscar la tarea programada existente
            $existingtask = $DB->get_record('task_scheduled', array(
                'component' => $task->component,
                'classname' => $task->classname
            ));

            if ($existingtask) {
                // Actualizar la tarea programada existente
                $task->id = $existingtask->id;
                $DB->update_record('task_scheduled', $task);
            } else {
                // Insertar la nueva tarea programada
                $taskid = $DB->insert_record('task_scheduled', $task);
            }
        } else {
            debugging('Error: Form data is empty.');
        }
    } else {
        debugging('Error: Form object is null.');
    }

    return $id;
}

/**
 * Updates an instance of the mod_exportanotas in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param object $moduleinstance An object from the form in mod_form.php.
 * @param mod_exportanotas_mod_form $mform The form.
 * @return bool True if successful, false otherwise.
 */
function exportanotas_update_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timemodified = time();
    $moduleinstance->id = $moduleinstance->instance;

    return $DB->update_record('exportanotas', $moduleinstance, true, true);
}

/**
 * Is a given scale used by the instance of mod_exportanotas?
 *
 * This function returns if a scale is being used by one mod_exportanotas
 * if it has support for grading and scales.
 *
 * @param int $moduleinstanceid ID of an instance of this module.
 * @param int $scaleid ID of the scale.
 * @return bool True if the scale is used by the given mod_exportanotas instance.
 */
function exportanotas_scale_used($moduleinstanceid, $scaleid) {
    global $DB;

    if ($scaleid && $DB->record_exists('exportanotas', array('id' => $moduleinstanceid, 'grade' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

/**
 * Asegura que el rol tenga las capacidades correctas.
 */
function assign_capabilities_to_role($roleid) {
    assign_capability('mod/exportanotas:manage', CAP_ALLOW, $roleid, context_system::instance());
    assign_capability('mod/exportanotas:view', CAP_ALLOW, $roleid, context_system::instance());
}

/**
 * Se ejecuta cuando se cargan las capacidades para asegurar que el rol tenga las capacidades correctas.
 */
function exportanotas_extend_settings_navigation($settingsnav, $context) {
    global $DB;

    // Asegurarse de que el rol tenga las capacidades correctas
    $role = $DB->get_record('role', array('shortname' => 'exportanotas_drive_role'));
    if ($role) {
        assign_capabilities_to_role($role->id);
    }
}

/**
 * Actualiza las capacidades de exportanotas.
 */
function exportanotas_update_capabilities() {
    update_capabilities('mod/exportanotas');
    exportanotas_extend_settings_navigation(null, context_system::instance());
}

/**
 * Checks if scale is being used by any instance of mod_exportanotas.
 *
 * This is used to find out if scale used anywhere.
 *
 * @param int $scaleid ID of the scale.
 * @return bool True if the scale is used by any mod_exportanotas instance.
 */
function exportanotas_scale_used_anywhere($scaleid) {
    global $DB;

    if ($scaleid && $DB->record_exists('exportanotas', array('grade' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

/**
 * Creates or updates grade item for the given mod_exportanotas instance.
 *
 * Needed by {@see grade_update_mod_grades()}.
 *
 * @param stdClass $moduleinstance Instance object with extra cmidnumber and modname property.
 * @param bool $reset Reset grades in the gradebook.
 * @return void.
 */
function exportanotas_grade_item_update($moduleinstance, $reset=false) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    $item = array();
    $item['itemname'] = clean_param($moduleinstance->name, PARAM_NOTAGS);
    $item['gradetype'] = GRADE_TYPE_VALUE;

    if ($moduleinstance->grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax']  = $moduleinstance->grade;
        $item['grademin']  = 0;
    } else if ($moduleinstance->grade < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid']   = -$moduleinstance->grade;
    } else {
        $item['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($reset) {
        $item['reset'] = true;
    }

    grade_update('/mod/exportanotas', $moduleinstance->course, 'mod', 'mod_exportanotas', $moduleinstance->id, 0, null, $item);
}

/**
 * Delete grade item for given mod_exportanotas instance.
 *
 * @param stdClass $moduleinstance Instance object.
 * @return grade_item.
 */
function exportanotas_grade_item_delete($moduleinstance) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('/mod/exportanotas', $moduleinstance->course, 'mod', 'exportanotas',
                        $moduleinstance->id, 0, null, array('deleted' => 1));
}

/**
 * Update mod_exportanotas grades in the gradebook.
 *
 * Needed by {@see grade_update_mod_grades()}.
 *
 * @param stdClass $moduleinstance Instance object with extra cmidnumber and modname property.
 * @param int $userid Update grade of specific user only, 0 means all participants.
 */
function exportanotas_update_grades($moduleinstance, $userid = 0) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    // Populate array of grade objects indexed by userid.
    $grades = array();
    grade_update('/mod/exportanotas', $moduleinstance->course, 'mod', 'mod_exportanotas', $moduleinstance->id, 0, $grades);
}

function mod_exportanotas_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $DB, $CFG;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    if ($filearea !== 'icon') {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_exportanotas', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

function mod_exportanotas_get_coursemodule_info($coursemodule) {
    global $DB, $CFG;

    $dbparams = array('id' => $coursemodule->instance);
    $fields = 'id, name, intro, introformat';
    if (!$exportanotas = $DB->get_record('exportanotas', $dbparams, $fields)) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $exportanotas->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('exportanotas', $exportanotas, $coursemodule->id, false);
    }

    $context = context_module::instance($coursemodule->id);
    $fs = get_file_storage();
    $iconfile = $fs->get_file($context->id, 'mod_exportanotas', 'icon', 0, '/', 'icon.png');
    if ($iconfile) {
        $info->icon = $CFG->wwwroot . '/pluginfile.php/' . $context->id . '/mod_exportanotas/icon/0/icon.png';
    } else {
        $info->icon = $CFG->wwwroot . '/mod/exportanotas/pix/icon.png';
    }

    // Añadir una clase personalizada al ícono
    $info->customdata = [
        'iconclass' => 'mod_exportanotas_icon'
    ];

    return $info;
}

/**
 * Extends the global settings navigation with the exportanotas settings.
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param navigation_node $exportanotasnode The node to add exportanotas settings to.
 */
function mod_exportanotas_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $exportanotasnode) {
    global $PAGE;

    // Asegúrate de que la página actual sea parte de tu plugin.
    if ($PAGE->cm && $PAGE->cm->modname === 'exportanotas') {
        $PAGE->requires->css('/mod/exportanotas/styles.css');
    }
}

/**
 * Deletes an instance of the exportanotas module.
 *
 * @param int $id ID of the module instance to be deleted
 * @return bool True on success, false on failure
 */
function exportanotas_delete_instance($id) {
    global $DB, $CFG;

    // Eliminar el registro de la base de datos
    if (!$exportanotas = $DB->get_record('exportanotas', array('id' => $id))) {
        return false;
    }

    $result = $DB->delete_records('exportanotas', array('id' => $id));

    if ($result) {
        // Eliminar las configuraciones del curso del archivo JSON
        $config_path = $CFG->dataroot . '/exportanotas_configurations.json';

        if (file_exists($config_path)) {
            $config_data = json_decode(file_get_contents($config_path), true);
            if ($config_data && isset($config_data['courses'])) {
                foreach ($config_data['courses'] as $index => $course) {
                    if ($course['course_id'] == $exportanotas->course) {
                        unset($config_data['courses'][$index]);
                        break;
                    }
                }
                // Reindexar el array para eliminar posibles huecos
                $config_data['courses'] = array_values($config_data['courses']);
                // Guardar los cambios en el archivo JSON
                file_put_contents($config_path, json_encode($config_data, JSON_PRETTY_PRINT));
            }
        }
    }

    return $result;
}