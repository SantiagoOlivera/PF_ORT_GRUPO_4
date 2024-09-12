<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_exportanotas_upgrade($oldversion) {
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
    } else {
        // Obtener el ID del rol existente
        $roleid = $DB->get_field('role', 'id', array('shortname' => $role->shortname));
    }

    if ($roleid) {
        // Establecer contextos permitidos para el rol (solo CONTEXT_SYSTEM)
        $contextlevel = CONTEXT_SYSTEM;
        if (!$DB->record_exists('role_context_levels', array('roleid' => $roleid, 'contextlevel' => $contextlevel))) {
            $DB->insert_record('role_context_levels', array('roleid' => $roleid, 'contextlevel' => $contextlevel));
        }

        // Asignar capacidades al rol
        assign_capabilities_to_role($roleid);
    }

    return true;
}
