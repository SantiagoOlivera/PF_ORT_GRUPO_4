<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_exportanotas_uninstall() {
    global $DB, $CFG;

    // Obtener el ID del rol basado en el shortname
    $role = $DB->get_record('role', array('shortname' => 'exportanotas_drive_role'));

    if ($role) {
        // Eliminar todas las asignaciones de este rol
        $DB->delete_records('role_assignments', array('roleid' => $role->id));

        // Eliminar todas las capacidades asignadas a este rol
        $DB->delete_records('role_capabilities', array('roleid' => $role->id));

        // Eliminar las definiciones de contextos para este rol
        $DB->delete_records('role_context_levels', array('roleid' => $role->id));

        // Eliminar el rol en sí
        $DB->delete_records('role', array('id' => $role->id));
    }

    // Definir las rutas de los archivos
    $config_path = $CFG->dataroot . '/exportanotas_configurations.json';
    $credentials_path = $CFG->dataroot . '/exportanotas_credentials.json';

    // Verificar y eliminar el archivo de configuración
    if (file_exists($config_path)) {
        unlink($config_path);
    }

    // Verificar y eliminar el archivo de credenciales
    if (file_exists($credentials_path)) {
        unlink($credentials_path);
    }

    // Eliminar la tarea programada
    $existingtask = $DB->get_record('task_scheduled', array(
        'component' => 'mod_exportanotas',
        'classname' => '\\mod_exportanotas\\task\\exportar_calificaciones_task'
    ));

    if ($existingtask) {
        $DB->delete_records('task_scheduled', array('id' => $existingtask->id));
    }

    return true;
}
