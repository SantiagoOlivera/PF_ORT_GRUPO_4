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
 * The main mod_exportanotas configuration form.
 *
 * @package     mod_exportanotas
 * @copyright   2024 Your Name <you@example.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/lib.php');

use mod_exportanotas\task\exportar_calificaciones_task;

/**
 * Module instance settings form.
 *
 * @package     mod_exportanotas
 * @copyright   2024 Your Name <you@example.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_exportanotas_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG, $USER, $DB;

        $mform = $this->_form;

        // Asegúrate de que tenemos el ID del curso
        if (isset($this->current->course)) {
            $course_id = $this->current->course;
            $course = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);
        } else {
            throw new coding_exception('Course ID not set in the form.');
        }

        // Obtener la categoría del curso y sus categorías padres
        $course_categories = $DB->get_records_sql("
            WITH RECURSIVE category_hierarchy AS (
                SELECT id, name, parent
                FROM {course_categories}
                WHERE id = :categoryid
                UNION ALL
                SELECT c.id, c.name, c.parent
                FROM {course_categories} c
                INNER JOIN category_hierarchy ch ON c.id = ch.parent
            )
            SELECT id, name
            FROM category_hierarchy
            WHERE id IS NOT NULL
        ", ['categoryid' => $course->category]);

        $mform->addElement('html', '
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tagify/4.9.7/tagify.css">
            <script src="https://cdnjs.cloudflare.com/ajax/libs/tagify/4.9.7/tagify.min.js"></script>
            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    var input = document.querySelector("input[name=\'prefijos_grupos\']");
                    new Tagify(input, {
                        delimiters: ",", // Delimitadores para separar las etiquetas
                        maxTags: 10, // Número máximo de etiquetas
                        dropdown: {
                            enabled: 0 // No mostrar dropdown
                        }
                    });
                });
            </script>
        ');


        // Añadir el encabezado "general"
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Añadir el campo "name"
        $mform->addElement('text', 'name', get_string('exportanotasname', 'mod_exportanotas'), array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }

        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'exportanotasname_help', 'mod_exportanotas');

        // Añadir el campo "intro"
        if ($CFG->branch >= 29) {
            $this->standard_intro_elements();
        } else {
            $this->add_intro_editor();
        }

        // Añadir el encabezado "Exportación de notas"
        $mform->addElement('header', 'exportacionnotas', get_string('exportacionnotas', 'mod_exportanotas'));

        // Añadir los campos de selección para la programación del cron
        $minutes = array_merge(array('*' => get_string('all', 'mod_exportanotas')), array_combine(range(0, 59), range(0, 59)));
        $mform->addElement('select', 'minute', get_string('minute', 'mod_exportanotas'), $minutes);
        $mform->setDefault('minute', '*');

        $hours = array_merge(array('*' => get_string('all', 'mod_exportanotas')), array_combine(range(0, 23), range(0, 23)));
        $mform->addElement('select', 'hour', get_string('hour', 'mod_exportanotas'), $hours);
        $mform->setDefault('hour', '*');

        $day_of_month = array('*' => get_string('all', 'mod_exportanotas'));
        for ($i = 1; $i <= 31; $i++) {
            $day_of_month[$i] = $i;
        }
        $mform->addElement('select', 'day', get_string('day', 'mod_exportanotas'), $day_of_month);
        $mform->setDefault('day', '*');

        $months = array(
            '*' => get_string('all', 'mod_exportanotas'),
            '1' => get_string('january', 'mod_exportanotas'),
            '2' => get_string('february', 'mod_exportanotas'),
            '3' => get_string('march', 'mod_exportanotas'),
            '4' => get_string('april', 'mod_exportanotas'),
            '5' => get_string('may', 'mod_exportanotas'),
            '6' => get_string('june', 'mod_exportanotas'),
            '7' => get_string('july', 'mod_exportanotas'),
            '8' => get_string('august', 'mod_exportanotas'),
            '9' => get_string('september', 'mod_exportanotas'),
            '10' => get_string('october', 'mod_exportanotas'),
            '11' => get_string('november', 'mod_exportanotas'),
            '12' => get_string('december', 'mod_exportanotas')
        );
        $mform->addElement('select', 'month', get_string('month', 'mod_exportanotas'), $months);
        $mform->setDefault('month', '*');

        $day_fo_week = array_merge(array('*' => get_string('everyday', 'mod_exportanotas')), array(
            '*' => get_string('all', 'mod_exportanotas'),
            '0' => get_string('sunday', 'mod_exportanotas'),
            '1' => get_string('monday', 'mod_exportanotas'),
            '2' => get_string('tuesday', 'mod_exportanotas'),
            '3' => get_string('wednesday', 'mod_exportanotas'),
            '4' => get_string('thursday', 'mod_exportanotas'),
            '5' => get_string('friday', 'mod_exportanotas'),
            '6' => get_string('saturday', 'mod_exportanotas')
        ));
        $mform->addElement('select', 'dayofweek', get_string('dayofweek', 'mod_exportanotas'), $day_fo_week);
        $mform->setDefault('dayofweek', '*');

        // Añadir checkbox para habilitar fechas entre
        $mform->addElement('advcheckbox', 'enable_dates', get_string('enable_dates', 'mod_exportanotas'), get_string('enable_dates_desc', 'mod_exportanotas'));
        $mform->setDefault('enable_dates', 0);

        // Añadir los campos de fecha
        $mform->addElement('date_selector', 'start_date', get_string('start_date', 'mod_exportanotas'));
        $mform->addElement('date_selector', 'end_date', get_string('end_date', 'mod_exportanotas'));

        // Añadir checkbox para activar "Categoría Agrupadora"
        $mform->addElement('advcheckbox', 'enable_agrupadora', get_string('enable_agrupadora', 'mod_exportanotas'), get_string('enable_agrupadora_desc', 'mod_exportanotas'));
        $mform->setDefault('enable_agrupadora', 0);

        $options = ['' => get_string('choosecategory', 'mod_exportanotas')];
        foreach ($course_categories as $category) {
            $options[$category->id] = $category->name;
        }

         // Añadir el campo "Categoría Agrupadora"
        $mform->addElement('select', 'categoria_agrupadora', get_string('categoria_agrupadora', 'mod_exportanotas'), $options);
        $mform->setDefault('categoria_agrupadora', '');
        $mform->hideIf('categoria_agrupadora', 'enable_agrupadora', 'notchecked');

        // Añadir el campo de prefijos de grupos con tags
        $mform->addElement('text', 'prefijos_grupos', get_string('prefijos_grupos', 'mod_exportanotas'));
        $mform->setType('prefijos_grupos', PARAM_TEXT);
        $mform->addHelpButton('prefijos_grupos', 'prefijos_grupos_help', 'mod_exportanotas');

        // Agregar elementos adicionales para la configuración de Google Drive si el usuario tiene el rol
        $context = context_course::instance($this->current->course);
        $hasrole = user_has_role_assignment($USER->id, $DB->get_field('role', 'id', array('shortname' => 'exportanotas_drive_role')), $context->id);

        if ($hasrole) {
            // Adding file manager for credentials.
            $options = array(
                'accepted_types' => array('.json'),
                'maxfiles' => 1, // Limitar a un solo archivo
                'subdirs' => 0
            );
            $mform->addElement('filemanager', 'credentials', get_string('credentials', 'mod_exportanotas'), null, $options);
            $mform->addHelpButton('credentials', 'credentials_help', 'mod_exportanotas');

            // Adding text field for folder_id.
            $mform->addElement('text', 'folder_id', get_string('folder_id', 'mod_exportanotas'));
            $mform->setType('folder_id', PARAM_TEXT);
            $mform->addHelpButton('folder_id', 'folder_id_help', 'mod_exportanotas');

            // Adding checkbox field for debug.
            $mform->addElement('advcheckbox', 'debug', get_string('debug', 'mod_exportanotas'), get_string('debug_desc', 'mod_exportanotas'));
            $mform->setDefault('debug', 0);
            $mform->addHelpButton('debug', 'debug_help', 'mod_exportanotas');
        }

        // Añadir el encabezado "Selección de notas"
        $mform->addElement('header', 'seleccionnotas', get_string('seleccionnotas', 'mod_exportanotas'));

        //Obtener los items de calificacion
        $grades_items = $this->get_grade_items_course($course_id);

        $not_averageable_grade_items = [];
        foreach($grades_items as $gi) {
            if(!$gi->is_averageable && !$gi->is_fixed){
                array_push($not_averageable_grade_items, $gi);
            }
        }

        $notas = array();

        $arrayGradeItemsOptionsSelect = array();
        $arrayGradeItemsOptionsSelect[''] = 'No seleccionada';
        foreach($grades_items as $gi){
            if(!$gi->is_fixed) {
                $arrayGradeItemsOptionsSelect[$gi->id] = $gi->itemname;
            }
        } 

        foreach($grades_items as $gi) {
            if($gi->is_averageable) {
                $n = array();
                
                // Checkbox principal para el ítem promediable
                $chk = $mform->createElement('advcheckbox', "grade_item_{$gi->id}", $gi->itemname, null, array('name' => "grade_item_{$gi->id}" ,'group'=>"notas", 'class' => "checkboxPrincipalNFC"), array(0, 1));

                $notas[] = $chk;
        
                // Sub-checkboxes para los ítems no promediables relacionados
                foreach($not_averageable_grade_items as $nagi) {
                    $subchk = $mform->createElement('advcheckbox', "grade_item_{$nagi->id}", $nagi->itemname, null, array('name' => "grade_item_{$nagi->id}" ,'group'=> "average_config_grade_item_{$gi->id}" ), array(0, 1));
                    $n[] = $subchk;
                }
        
                // Crear el grupo para los sub-checkboxes, pero lo ocultamos inicialmente
                $subgroup = $mform->createElement('group', "average_config_grade_item_{$gi->id}", null, $n, array('<br>'), true);
                $subgroup->setAttributes(['class' => 'pl-4 hiddenClassName',]); // Ocultamos el grupo inicialmente
                $notas[] = $subgroup;
        
            
            } else if($gi->is_fixed) {

                $chk = $mform->createElement('advcheckbox', "grade_item_{$gi->id}", $gi->itemname, null, array('name' => "grade_item_{$gi->id}" ,'group'=>"notas", 'class' => "checkboxFixedGradeItem", 'gradeitem' => "{$gi->id}"), array(0, 1));
                $select = $mform->createElement('select', "config_grade_item_{$gi->id}" , null, $arrayGradeItemsOptionsSelect, array( 'class'=> 'pl-4', 'gradeitem' => "{$gi->id}") );

                $notas[] = $chk;
                $notas[] = $select;

            } else {
                // Items de calificación configurados en el curso
                $notas[] = $mform->createElement('advcheckbox', "grade_item_{$gi->id}", $gi->itemname, null, array('name' => "grade_item_{$gi->id}", 'group'=>'notas'), array(0, 1)); 
            }
        }
        
        if(sizeof($grades_items) == 0){
            $mensaje_grade_items_no_configuradas = get_string('items_de_calificacion_no_configurados', 'mod_exportanotas');
            $mform->addElement('html', "<div class='p-5'><h6 class='text-center alert alert-primary'>{$mensaje_grade_items_no_configuradas}</h6></div>");
        }
        
        $mform->addGroup($notas, 'seleccion_de_notas', '', array('<br>'), true);
        $mform->addElement('header', 'others', get_string('others', 'mod_exportanotas'));
        
        // Agregar JavaScript para manejar la visibilidad de los ítems
        $mform->addElement('html', '<script>
    document.addEventListener("DOMContentLoaded", function() {
    var element = document.getElementsByClassName("checkboxPrincipalNFC")[1]; 
   
        var subGroup = document.getElementsByClassName("hiddenClassName")[0];
      
         function toggleVisibility(){
            if (element.checked) {
         
    
                subGroup.classList.remove("hidden");    
            } else {
                subGroup.classList.add("hidden"); // Oculta el elemento si la checkbox no está seleccionada
            }
        }
        toggleVisibility();
        element.addEventListener("change", toggleVisibility);


        var fixedcheckboxes = document.querySelectorAll(`input[type="checkbox"].checkboxFixedGradeItem`);
        var functionToggle = function(e) {
            var checkbox = e;
            var gi = checkbox.getAttribute("gradeitem");
            var el = document.querySelector(`select[gradeitem="${gi}"]`);
            if(checkbox.checked){
                el.classList.remove("hidden");
            } else {
                el.classList.add("hidden");
            }
        };
        fixedcheckboxes.forEach(e => {
            e.addEventListener( "change" , function(event) { functionToggle(event.currentTarget); });
            functionToggle(e);
        });

    });
</script>');
        
        // $mform->addElement('html', '
        //     <script type="text/javascript">
                
        //         function toggleVisibility(elementId) {
        //             console.log("Esto entra", elementId)
        //             var element = document.getElementsByClassName(elementId)[0]
        //             if (element.classList.contains("hidden")) {
        //                 element.classList.remove("hidden");
                        
        //             } else {
        //                  element.classList.add("hidden");
                        
        //             }
                    
        //         }
            
        //     </script>
        // ');

        // Add standard grading elements.
        $this->standard_grading_coursemodule_elements();

        // Add standard elements.
        $this->standard_coursemodule_elements();

        // Add standard buttons.
        $this->add_action_buttons();

        // Ocultar los campos de fecha inicialmente
        $mform->hideIf('start_date', 'enable_dates', 'notchecked');
        $mform->hideIf('end_date', 'enable_dates', 'notchecked');

        // Código JavaScript para habilitar/deshabilitar los campos de fecha en tiempo real
        $mform->addElement('html', '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var enableDatesCheckbox = document.querySelector("input[name=\'enable_dates\']");
                var startDateField = document.querySelector("input[name=\'start_date[day]\']");
                var endDateField = document.querySelector("input[name=\'end_date[day]\']");
                var toggleDateFields = function() {
                    if (enableDatesCheckbox.checked) {
                        startDateField.disabled = false;
                        endDateField.disabled = false;
                    } else {
                        startDateField.disabled = true;
                        endDateField.disabled = true;
                    }
                };
                enableDatesCheckbox.addEventListener("change", toggleDateFields);
                toggleDateFields();
            });
        </script>');

        $elements_to_remove = [
            'modstandardgrade', // Calificar
            'grade',
            'gradecat',
            'gradepass',

            'modstandardelshdr', // Ajustes comunes del módulo
            //'visible', no se debe eliminar porque da error
            'cmidnumber',
            'lang',
            //'availabilityconditionsjson', no se debe eliminar porque da error

            'activitycompletionheader', // Finalización de la actividad
            'unlockcompletion',
            //'completionunlocked', no se debe eliminar porque da error
            'completion',
            'completionusegrade',
            'completionpassgrade',
            'completionexpected',

            'availabilityconditionsheader', // Restricciones de acceso
            //'course', no se debe eliminar porque da error
            //'coursemodule', no se debe eliminar porque da error

            'tagshdr', // Marcas
            'tags',

            'competenciessection', // Competencias
            'competencies',
            'competency_rule',
            'override_grade'
        ];

        foreach ($elements_to_remove as $element) {
            if ($mform->elementExists($element)) {
                // Eliminar la sección
                $mform->removeElement($element);
            }
        }

    }

    public function data_postprocessing($data) {
        global $CFG, $USER, $DB;

        // Verificar si el usuario tiene el rol específico
        $context = context_course::instance($this->current->course);
        $roleid = $DB->get_field('role', 'id', array('shortname' => 'exportanotas_drive_role'));
        $hasrole = user_has_role_assignment($USER->id, $roleid, $context->id);

        if ($hasrole) {
            $fs = get_file_storage();
            $draftitemid = $data->credentials;

            // Archivo temporal subido
            if ($draftitemid) {
                $usercontext = context_user::instance($USER->id);

                // Guardar el archivo del área de borrador al área del plugin
                file_save_draft_area_files($draftitemid, context_system::instance()->id, 'mod_exportanotas', 'credentials', 0, array('subdirs' => 0, 'maxfiles' => 1));

                // Obtener el archivo del área del plugin
                $storedfiles = $fs->get_area_files(context_system::instance()->id, 'mod_exportanotas', 'credentials', 0, 'id', false);
                if ($storedfiles) {
                    $storedfile = reset($storedfiles);

                    // Definir ruta de almacenamiento específico
                    $credentials_path = $CFG->dataroot . '/exportanotas_credentials.json';

                    // Eliminar el archivo existente si existe
                    if (file_exists($credentials_path)) {
                        unlink($credentials_path);
                    }

                    // Mover el archivo a la ubicación especificada
                    $storedfile->copy_content_to($credentials_path);
                    if (file_exists($credentials_path)) {
                        $data->credentials = 'exportanotas_credentials.json';
                    } else {
                        mtrace("Error al mover el archivo a $credentials_path");
                    }
                } else {
                    mtrace("Error: No se encontró el archivo almacenado.");
                }
            }
        }

        // Guardar configuraciones adicionales en el archivo JSON
        $config_path = $CFG->dataroot . '/exportanotas_configurations.json';

        // Leer configuraciones actuales
        $config_data = array();
        if (file_exists($config_path)) {
            $config_data = json_decode(file_get_contents($config_path), true);
        }
        else
        {
            $config_data = array(
                'folder_id' => $data->folder_id,
                'debug' => $data->debug
            );
        }

        // Guardar configuraciones generales solo si se tiene el rol
        if ($hasrole) {
            $config_data['folder_id'] = $data->folder_id;
            $config_data['debug'] = $data->debug;
        }

        // Formatear fechas
        $start_date = !empty($data->start_date) ? date('Y-m-d', $data->start_date) : null;
        $end_date = !empty($data->end_date) ? date('Y-m-d', $data->end_date) : null;
        if ($data->enable_dates == 0) {
            $start_date = null;
            $end_date = null;
        }

        // Actualizar o añadir las configuraciones del curso actual
        $course_id = $this->current->course;

        // Obtener información del curso
        $course = $DB->get_record('course', array('id' => $course_id), 'fullname, shortname');


        $course_name = isset($course->fullname) ? $course->fullname : '';
        $course_short_name = isset($course->shortname) ? $course->shortname : '';

        $execution_parameters = array(
            'minute' => $data->minute,
            'hour' => $data->hour,
            'day' => $data->day,
            'month' => $data->month,
            'dayofweek' => $data->dayofweek,
            'enable_dates' => $data->enable_dates,
            'start_date' => $start_date,
            'end_date' => $end_date
        );

        // Buscar el índice del curso en el array
        $course_index = null;
        if (isset($config_data['courses'])) {
            foreach ($config_data['courses'] as $index => $course) {
                if ($course['course_id'] == $course_id) {
                    $course_index = $index;
                    break;
                }
            }
        }

        // Formatear y guardar "Categoría Agrupadora"
        if ($data->enable_agrupadora) {
            $categoria_agrupadora = $DB->get_field('course_categories', 'name', ['id' => $data->categoria_agrupadora]);
            $data->categoria_agrupadora = $categoria_agrupadora ? $categoria_agrupadora : null;
        } else {
            $data->categoria_agrupadora = null;
        }

        // Procesar prefijos de grupos
        if (!empty($data->prefijos_grupos)) {
            $prefijos_grupos_array = json_decode($data->prefijos_grupos, true);
            $prefijos_grupos_array = array_map(function($item) {
                return $item['value'];
            }, $prefijos_grupos_array);
            $data->prefijos_grupos = implode(',', $prefijos_grupos_array);
        } else {
            $data->prefijos_grupos = '';
        }

        //Guardamos los items de calificacion defualt o fijos si no estan configurados en el curso
        //$seleccion_de_notas = $this->save_unset_grade_items($data->seleccion_de_notas);
        $seleccion_de_notas = $data->seleccion_de_notas;

        // Si el curso ya existe, actualizarlo, si no, añadirlo
        if ($course_index !== null) {
            $config_data['courses'][$course_index]['execution_parameters'] = $execution_parameters;
            $config_data['courses'][$course_index]['course_name'] = html_entity_decode($course_name);
            $config_data['courses'][$course_index]['course_short_name'] = html_entity_decode($course_short_name);
            $config_data['courses'][$course_index]['categoria_agrupadora'] = $data->categoria_agrupadora;
            $config_data['courses'][$course_index]['prefijos_grupos'] = $data->prefijos_grupos;
            $config_data['courses'][$course_index]['seleccion_de_notas'] = $seleccion_de_notas;
        } else {
            $config_data['courses'][] = array(
                'course_id' => $course_id,
                'course_name' => html_entity_decode($course_name),
                'course_short_name' => html_entity_decode($course_short_name),
                'categoria_agrupadora' => $data->categoria_agrupadora,
                'prefijos_grupos' => $data->prefijos_grupos,
                'seleccion_de_notas' => $seleccion_de_notas,
                'execution_parameters' => $execution_parameters,
            );
        }

        // Convertir el array a JSON y guardarlo en el archivo
        file_put_contents($config_path, json_encode($config_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (file_exists($config_path)) {
            $data->configurations = 'exportanotas_configurations.json';
        } else {
            mtrace("Error al guardar las configuraciones en $config_path");
        }

        return $data;
    }
    

    public function data_preprocessing(&$default_values) {
        global $CFG, $USER, $DB;

        $context = context_system::instance();
        $fs = get_file_storage();
        $existe_configuracion_curso = false;
        // Precargar los valores guardados previamente
        $config_path = $CFG->dataroot . '/exportanotas_configurations.json';
        if (file_exists($config_path)) {
            $config_data = json_decode(file_get_contents($config_path), true);
            if ($config_data) {
                $default_values['folder_id'] = $config_data['folder_id'];
                $default_values['debug'] = $config_data['debug'];

                // Buscar configuraciones del curso actual
                $course_id = $this->current->course;
                if (isset($config_data['courses'])) {
                    foreach ($config_data['courses'] as $course) {
                        if ($course['course_id'] == $course_id) {
                            $existe_configuracion_curso = true;
                            $default_values['minute'] = $course['execution_parameters']['minute'];
                            $default_values['hour'] = $course['execution_parameters']['hour'];
                            $default_values['day'] = $course['execution_parameters']['day'];
                            $default_values['month'] = $course['execution_parameters']['month'];
                            $default_values['dayofweek'] = $course['execution_parameters']['dayofweek'];
                            $default_values['enable_dates'] = $course['execution_parameters']['enable_dates'];
                            $default_values['start_date'] = !empty($course['execution_parameters']['start_date']) ? strtotime($course['execution_parameters']['start_date']) : null;
                            $default_values['end_date'] = !empty($course['execution_parameters']['end_date']) ? strtotime($course['execution_parameters']['end_date']) : null;
                            $default_values['enable_agrupadora'] = !empty($course['categoria_agrupadora']);
                            $default_values['categoria_agrupadora'] = !empty($course['categoria_agrupadora']) ? $DB->get_field('course_categories', 'id', ['name' => $course['categoria_agrupadora']]) : '';

                            // Procesar prefijos_grupos
                            if (!empty($course['prefijos_grupos'])) {
                                if (is_array($course['prefijos_grupos'])) {
                                    $default_values['prefijos_grupos'] = implode(', ', $course['prefijos_grupos']);
                                } else {
                                    $default_values['prefijos_grupos'] = $course['prefijos_grupos'];
                                }
                            } else {
                                $default_values['prefijos_grupos'] = '';
                            }

                            //Setear valores guardados en la configuracion de las notas seleccionadas
                            if(isset($course['seleccion_de_notas'])) {
                                //Si ya esta definidas las notas seteamos los valores cofigurados
                                foreach($course['seleccion_de_notas'] as $key => $val) {
                                    $default_values['seleccion_de_notas'][$key] = $val;
                                }
                            }
                            break;
                        }
                    }
                }
            }
        }

        if(!$existe_configuracion_curso){
            $course_id = $this->current->course;
            // Defaults seleccion de notas
            $grades_items = $this->get_grade_items_course($course_id);
            //Por defecto todas las notas seleccionadas
            $default_values['seleccion_de_notas'] = array();
            foreach($grades_items as $gi) {
                $default_values['seleccion_de_notas']["grade_item_{$gi->id}"] = '0';
            }
        }

        // Cargar el archivo exportanotas_credentials.json si ya existe
        $files = $fs->get_area_files($context->id, 'mod_exportanotas', 'credentials', 0, 'id', false);
        if ($files) {
            $file = reset($files);
            if ($file) {
                $draftitemid = file_get_submitted_draft_itemid('credentials');
                file_prepare_draft_area($draftitemid, $context->id, 'mod_exportanotas', 'credentials', 0, array('subdirs' => 0, 'maxfiles' => 1));
                $default_values['credentials'] = $draftitemid;
            }
        }
    }

    public function validation($data, $files) {
        global $USER, $DB;

        $errors = parent::validation($data, $files);

        $context = context_course::instance($this->current->course);
        $roleid = $DB->get_field('role', 'id', array('shortname' => 'exportanotas_drive_role'));
        $hasrole = user_has_role_assignment($USER->id, $roleid, $context->id);

        if ($hasrole) {
            if (empty($data['credentials'])) {
                $errors['credentials'] = get_string('required');
            }
            if (empty($data['folder_id'])) {
                $errors['folder_id'] = get_string('required');
            }
        }

        return $errors;
    }

    public function save_unset_grade_items($seleccion_de_notas) {
        //Guarda los items de calificacion si no estan configurados
        foreach($seleccion_de_notas as $k => $val){
            $id = str_replace('grade_item_', '', $k);
            if(!is_numeric($id) && $id != "NFC") {
                $grade_item = $this->save_default_grade_item_in_course($id);
                if($grade_item) {
                    $seleccion_de_notas["grade_item_{$grade_item->id}"] = $val;
                    unset($seleccion_de_notas[$k]);
                }
            }
        }
        return $seleccion_de_notas;
    }

    public function get_grade_items_course($course_id) {
        global $USER, $DB;
        $default_grade_items = exportar_calificaciones_task::get_default_grade_items();
        $sql_grades_items = "SELECT 
                                gi.id AS id, 
                                gi.itemname AS itemname,
                                true AS is_set,
                                false AS is_fixed 
                            FROM 
                                {grade_items} AS gi 
                            WHERE 
                                gi.courseid = :courseid AND 
                                gi.itemname IS NOT NULL AND 
                                gi.itemname <> '' ";

        $grades_items = $DB->get_records_sql($sql_grades_items, ['courseid' => $course_id]);

        //Agrego items de calificacion por defecto si no existen en la configuracion del cur
        /* foreach($default_grade_items as $dgi) {
            $ret = array_filter($grades_items, function($gi) use ($dgi) {
                return $dgi->itemname == $gi->itemname;
            });
            $item = reset($ret);           
            if(!$item){
                array_push($grades_items, $dgi);    
            }
        }
        */
        foreach($default_grade_items as $dgi) {
            array_push($grades_items, $dgi); 
        }
        return $grades_items;
    }



    public function get_grade_items_course_without_default_grade_items($course_id) {
        global $USER, $DB;

        $sql_grades_items = "SELECT 
                                gi.id AS id, 
                                gi.itemname AS itemname
                            FROM 
                                {grade_items} AS gi 
                            WHERE 
                                gi.courseid = :courseid AND 
                                gi.itemname IS NOT NULL AND 
                                gi.itemname <> '' AND
                                gi.itemmodule <> 'exportanotas' ";

        $grades_items = $DB->get_records_sql($sql_grades_items, ['courseid' => $course_id]);

        return $grades_items;
    }


    public function save_default_grade_item_in_course($item_id) {
        //Graba item fijo indicado por el parametro del id
        $ret = null;
        $default_grade_items = exportar_calificaciones_task::get_default_grade_items();

        $found_item = array_filter($default_grade_items, function($item) use ($item_id) {
            return $item->id === $item_id;
        });

        if (!empty($found_item)) {
            $item = reset($found_item);
            $ret = $this->save_grade_item( $item );
        }

        return $ret;

    }

    public function save_grade_item( $item ) {
        //Graba nuevo item de califiacion si no existe para el curso actual
        global $USER, $DB;
        $course_id = $this->current->course;
        $gi = null;

        $sql_grades_items = "SELECT 
                                gi.id AS id, 
                                gi.itemname AS itemname
                            FROM 
                                {grade_items} AS gi 
                            WHERE 
                                gi.courseid = :courseid AND 
                                gi.itemname = :itemname ";

        $grade_item = $DB->get_records_sql($sql_grades_items, ['courseid' => $course_id, 'itemname' => "'{$item->itemname}'"]);

        if(sizeof($grade_item) == 0) {

            $grade_data = new stdClass();
            $grade_data->courseid = $course_id;
            $grade_data->itemname = $item->itemname;
            $grade_data->itemtype = $item->itemtype;
            $grade_data->itemmodule = $item->itemmodule;
            $grade_data->gradetype = $item->gradetype;
            $grade_data->grademax = $item->grademax;
            $grade_data->grademin = $item->grademin;
            $grade_data->gradepass = $item->gradepass;

            $gi = new grade_item($grade_data);
            $is_saved = $gi->insert();
            if($is_saved){
                $gi = $DB->get_records_sql($sql_grades_items, ['courseid' => $course_id, 'itemname' => "'{$item->itemname}'"]);
            }
        }

        return $gi;
    }
    

}