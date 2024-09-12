<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_exportanotas_install() {
    global $DB;

    // Definir el rol usando cadenas de idioma
    $role = new stdClass();
    $role->name = get_string('role_exportanotas_name', 'mod_exportanotas');
    $role->shortname = 'exportanotas_drive_role';
    $role->description = get_string('role_exportanotas_description', 'mod_exportanotas');
    $role->archetype = 'user';

    // Verificar si el rol ya existe
    if (!$DB->record_exists('role', array('shortname' => $role->shortname))) {
        // Crear el rol en la base de datos
        $roleid = create_role($role->name, $role->shortname, $role->description, $role->archetype);

        if ($roleid) {
            // Establecer contextos permitidos para el rol
            $contextlevels = array(CONTEXT_SYSTEM);
            foreach ($contextlevels as $contextlevel) {
                if (!$DB->record_exists('role_context_levels', array('roleid' => $roleid, 'contextlevel' => $contextlevel))) {
                    $DB->insert_record('role_context_levels', array('roleid' => $roleid, 'contextlevel' => $contextlevel));
                }
            }
        }
    }
}
