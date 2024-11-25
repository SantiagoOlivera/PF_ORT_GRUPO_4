<?php
namespace mod_exportanotas\task;

defined('MOODLE_INTERNAL') || die();

class exportar_calificaciones_task extends \core\task\scheduled_task {
    
    public function get_name() {
        return get_string('exportar_calificaciones_task', 'mod_exportanotas');
    }

    public function execute() {
        global $DB, $CFG;

        // Configuraciones
        $credentials_path = $CFG->dataroot.'/exportanotas_credentials.json';
        $config_path = $CFG->dataroot . '/exportanotas_configurations.json';

        if (file_exists($config_path)) {
            $config_data = json_decode(file_get_contents($config_path));
        } else {
            mtrace("Error: No se encontró el archivo de configuración en $config_path");
            return;
        }

        $root_folder_id = $config_data->folder_id;
        $debug = $config_data->debug;

        $trace = function($message) use ($debug) {
            if ($debug) {
                mtrace($message);
            }
            echo "{$message} \n ";
        };

        $imprimir_inicio = function () use ($trace) {
            $trace("----------------------------------------------------------------------------------------------------------");
            $trace("Iniciando la exportación de notas...");
        };

        $imprimir_fin = function () use ($trace) {
            $trace("Exportación de notas finalizada...");
            $trace("----------------------------------------------------------------------------------------------------------");
        };

        $imprimir_inicio();

        // Obtener los cursos a procesar
        $obtener_cursos_a_procesar = function($config_data) {
            $current_time = time();
            $current_minute = (int) date('i', $current_time);
            $current_hour = (int) date('H', $current_time);
            $current_day = (int) date('d', $current_time);
            $current_month = (int) date('m', $current_time);
            $current_dayofweek = (int) date('w', $current_time);

            $cursos_a_procesar = [];

            foreach ($config_data->courses as $course_config) {
                $course_id = $course_config->course_id;
                $params = $course_config->execution_parameters;

                // Verificar cada condición
                if (($params->minute === '*' || (int)$params->minute === $current_minute) &&
                    ($params->hour === '*' || (int)$params->hour === $current_hour) &&
                    ($params->day === '*' || (int)$params->day === $current_day) &&
                    ($params->month === '*' || (int)$params->month === $current_month) &&
                    ($params->dayofweek === '*' || (int)$params->dayofweek === $current_dayofweek) &&
                    (!$params->enable_dates || ($params->start_date <= $current_time && $params->end_date >= $current_time))) {

                    $cursos_a_procesar[] = $course_id;
                }
            }

            return $cursos_a_procesar;
        };

        // Obtener calificaciones de un curso
        $obtener_calificaciones = function($course_id, $prefijos_grupos) use ($DB, $trace, $CFG) {
            $trace("Obteniendo calificaciones del curso ID: $course_id...");

            // Obtener los campos personalizados
            $custom_fields = []; //$DB->get_records('user_info_field', null, '', 'id, shortname, name');

            // Obtener datos de los usuarios incluyendo campos personalizados
            $sql = "SELECT DISTINCT
                        u.id AS userid,
                        u.lastname AS apellidos,
                        u.firstname AS nombre,
                        u.username,
                        u.institution AS institucion,
                        u.department AS departamento,
                        gm.groupid AS groupid,";

            // Añadir los campos personalizados al SELECT
            foreach ($custom_fields as $field) {
                if ($field->shortname === 'class') {
                $sql .= "(SELECT data FROM {user_info_data} WHERE userid = u.id AND fieldid = {$field->id}) AS {$field->shortname}, ";
                break;
                }
            }

            // Remover la última coma y espacio
            $sql = rtrim($sql, ', ');

            $sql .= " FROM {user} u
                    JOIN {user_enrolments} ue ON ue.userid = u.id
                    JOIN {enrol} e ON e.id = ue.enrolid
                    JOIN {course} c ON c.id = e.courseid
                    LEFT JOIN (
                        SELECT gm.userid, MIN(gm.groupid) AS groupid
                        FROM {groups_members} gm
                        JOIN {groups} g ON gm.groupid = g.id
                        WHERE g.courseid = :courseidgroup -- Filtrar por el curso actual
                        GROUP BY gm.userid
                    ) AS gm ON u.id = gm.userid
                    JOIN {role_assignments} ra ON ra.userid = u.id
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    JOIN {role} r ON r.id = ra.roleid
                    WHERE c.id = :courseid
                    AND r.shortname = 'student'";

            $sql_params['courseid'] = $course_id;
            $sql_params['courseidgroup'] = $course_id;
            if (!empty($prefijos_grupos)) {
                $prefijos_array = explode(',', $prefijos_grupos);
                $sql .= " AND (";
                $conditions = [];
                foreach ($prefijos_array as $index => $prefijo) {
                    $param_name = "prefijo_$index";
                    $conditions[] = "gm.groupid IN (SELECT id FROM {groups} WHERE name LIKE :$param_name)";
                    $sql_params[$param_name] = $prefijo.'%';
                }
                $sql .= implode(' OR ', $conditions);
                $sql .= ")";
            }

            $results = $DB->get_records_sql($sql, $sql_params);
            if (!$results) {
                $results = []; // Asegurarse de que $results sea un array
                $trace("No se encontraron registros de calificaciones para el curso ID: $course_id.");
            }

            //Obtener las notas seleccionadas en el curso
            $seleccion_de_notas = exportar_calificaciones_task::get_seleccion_de_notas_course($course_id);
            $sql_items = exportar_calificaciones_task::get_sql_where_seleccion_de_notas($seleccion_de_notas);

            // Obtener los nombres de las actividades calificables excluyendo asistencia
            $sql_activities = "SELECT
                        gi.id AS gradeitemid,
                        gi.itemname,
                        gi.grademax,
                        gi.gradepass,
                        gi.sortorder,
                        cs.section,
                        FIND_IN_SET(cm.id, cs.sequence) AS position,
                        cm.id AS cmid,
                        false AS is_fixed
                    FROM
                        {course_sections} cs
                    JOIN
                        {course_modules} cm ON FIND_IN_SET(cm.id, cs.sequence) > 0
                    JOIN
                        /* {modules} m ON cm.module = m.id */
                        {modules} m ON cm.module = m.id AND m.name = 'exportanotas'
                    JOIN
                        /* {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemmodule = m.name */
                        {grade_items} gi ON gi.courseid = cs.course
                    WHERE
                        cs.course = :courseid
                        /* AND gi.itemtype = 'mod' */
                        AND gi.itemmodule != 'attendance' 
                        AND {$sql_items}
                    ORDER BY
                        cs.section, position";

            $activities = $DB->get_records_sql($sql_activities, ['courseid' => $course_id ]);

            $fixed_grade_items = exportar_calificaciones_task::get_fixed_grade_items_to_export($course_id);

            //Agregamos los items de calificacion fijos o por defecto que se calculan a partir de otros items de calificación
            if(sizeof($fixed_grade_items) > 0){
                foreach($fixed_grade_items as $fgi){
                    array_push($activities, $fgi);
                }
            }

            if (!$activities) {
                $trace("No se encontraron actividades calificables para el curso ID: $course_id.");
                return false;
            }

            // Obtener los nombres de los grupos del curso actual
            $sql_groups = "SELECT g.id, g.name, g.idnumber
            FROM {groups} g
            WHERE g.courseid = :courseid";
            $groups = $DB->get_records_sql($sql_groups, ['courseid' => $course_id ]);

            // Generar los nombres de las columnas para evaluables
            $evaluable_columns = [];
            foreach ($activities as $activity) {
                $evaluable_columns[] = $activity->itemname;
            }

            // Añadir nombres de columnas fijas
            $fixed_column_names = [
                'userid', 'Apellido(s)', 'Nombre', 'Nombre de usuario', 'Institución', 'Departamento'
            ];

            // Añadir nombres de campos personalizados
            $custom_field_names = [];
            foreach ($custom_fields as $field) {
                $custom_field_names[] = $field->name;
            }

            $fixed_column_names = array_merge($fixed_column_names, $custom_field_names);

            // Añadir el groupid
            $fixed_column_names[] = 'groupid';

            // Añadir la columna de Asistencia si el módulo está instalado
            $attendance_installed = false;
            if (file_exists($CFG->dirroot . '/mod/attendance')) {
                $attendance_installed = true;
                $fixed_column_names[] = 'Asistencia';
            }

            // Combinar todas las columnas
            $column_names = array_merge($fixed_column_names, $evaluable_columns);

            // Crear la matriz de datos, comenzando con los nombres de las columnas
            $data_matrix = [];
            $data_matrix[] = $column_names;

            // Añadir los datos de los resultados
            foreach ($results as $userid => $user_data) {
                $row = [
                    $user_data->userid,
                    $user_data->apellidos,
                    $user_data->nombre,
                    $user_data->username,
                    $user_data->institucion,
                    $user_data->departamento
                ];

                // Añadir los datos de los campos personalizados
                foreach ($custom_fields as $field) {
                    $field_shortname = strtolower($field->shortname);
                    $user_data_property = array_change_key_case((array)$user_data, CASE_LOWER);
                    $row[] = isset($user_data_property[$field_shortname]) ? $user_data_property[$field_shortname] : '';
                }

                // Añadir el nombre del grupo
                $group_name = isset($groups[$user_data->groupid]) ? $groups[$user_data->groupid]->name : 'no hay datos';
                $row[] = $group_name;

                // Añadir la asistencia si el módulo está instalado
                if ($attendance_installed) {
                    // Obtener la asistencia del usuario
                    $sql_attendance = "SELECT
                                            COUNT(DISTINCT als.id) AS total_sesiones,
                                            SUM(ats.grade) AS asistencias,
                                            SUM(ats.grade) / (COUNT(DISTINCT als.id) * max(ats.grade)) * 10 as percentage
                                        FROM {attendance_log} al
                                        JOIN {attendance_sessions} als ON al.sessionid = als.id
                                        JOIN {attendance_statuses} ats ON ats.id = al.statusid
                                        JOIN {attendance} a ON a.id = als.attendanceid
                                        WHERE al.studentid = :userid
                                        AND a.course = :courseid
                                        AND ats.attendanceid = als.attendanceid";

                    $attendance_data = $DB->get_record_sql($sql_attendance, ['userid' => $user_data->userid, 'courseid' => $course_id]);

                    if ($attendance_data && $attendance_data->total_sesiones > 0) {
                        $attendance_value = $attendance_data->percentage;
                        if ($attendance_value == floor($attendance_value)) {
                            $attendance_value = number_format($attendance_value, 0);
                        } else {
                            $attendance_value = number_format($attendance_value, 1, '.', '');
                        }
                        $row[] = $attendance_value;
                    } else {
                        $row[] = '-';
                    }
                }

                // Añadir las notas de las actividades calificables
                foreach ($activities as $activity) {
                    $sql_grade = '';
                    $grade = null;
                    if(!$activity->is_fixed) {
                        $sql_grade = "SELECT 
                                        finalgrade, 
                                        aggregationstatus
                                    FROM 
                                        {grade_grades}
                                    WHERE 
                                        itemid = :itemid AND 
                                        userid = :userid";

                        $grade = $DB->get_record_sql($sql_grade, ['itemid' => $activity->gradeitemid, 'userid' => $user_data->userid]);

                    } else {
                        //Para NFC nota final promediable
                        if($activity->is_averageable){
                            $sql_where_items = exportar_calificaciones_task::get_sql_grade_items_for_avg_fixed_grade_item($course_id, $activity->gradeitemid);
                            $sql_grade = "SELECT 
                                            AVG(finalgrade) AS finalgrade, 
                                            'used' AS aggregationstatus
                                        FROM 
                                            {grade_grades}
                                        WHERE 
                                            itemid IN {$sql_where_items} AND 
                                            userid = :userid";
                                            
                            $grade = $DB->get_record_sql($sql_grade, ['userid' => $user_data->userid]);
                        } else {
                            //Notas fijas ejempl FJU, ect.
                            $sql_where_id_item = exportar_calificaciones_task::get_sql_grade_items_for_selectable_fixed_grade_item($course_id, $activity->gradeitemid);
                            if(!empty($sql_where_id_item)){
                                $sql_grade = "SELECT 
                                    finalgrade AS finalgrade, 
                                    'used' AS aggregationstatus
                                FROM 
                                    {grade_grades}
                                WHERE 
                                    itemid = {$sql_where_id_item} AND 
                                    userid = :userid";
                                $grade = $DB->get_record_sql($sql_grade, ['userid' => $user_data->userid]);
                            }
                        }   
                    }
                    
                    if ($grade) {
                        if (in_array($grade->aggregationstatus, ['unknown', 'dropped', 'novalue']) || $grade->finalgrade === null) {
                            $grade_value = '';
                        } elseif ($grade->finalgrade == 0) {
                            $grade_value = '-';
                        } elseif ($grade->finalgrade > 0) {
                            $converted_grade = ($grade->finalgrade / $activity->grademax) * 10;
                            if ($converted_grade == floor($converted_grade)) {
                                $grade_value = number_format($converted_grade, 0);
                            } else {
                                $grade_value = number_format($converted_grade, 1, '.', '');
                            }
                            if ($grade->finalgrade >= $activity->gradepass) {
                                $grade_value .= " - Aprobado";
                            } else {
                                $grade_value .= " - Reprobado";
                            }
                        } else {
                            $grade_value = '-';
                        }
                    } else {
                        $grade_value = '';
                    }
                    $row[] = $grade_value;
                }

                $data_matrix[] = $row;
            }

            return $data_matrix;
        };


        // Generar CSV
        $generar_csv = function($data_matrix) use ($trace) {
            $trace("Generando contenido CSV...");

            // Obtener los nombres de las columnas
            $column_names = array_shift($data_matrix);

            // Crear el contenido del CSV
            $csv_content = implode(';', $column_names) . "\n";

            // Agregar los datos de cada fila
            foreach ($data_matrix as $row) {
                $csv_content .= implode(';', $row) . "\n";
            }

            $trace("Contenido CSV generado en memoria.");
            return $csv_content;
        };


        $leer_credenciales = function($credentials_path) use ($trace) {
            $trace("Leyendo credenciales...");
            $credentials = json_decode(file_get_contents($credentials_path), true);
            if (!$credentials) {
                $trace("Error al leer las credenciales desde $credentials_path");
                return false;
            }
            $trace("Credenciales leídas correctamente.");
            return $credentials;
        };

        $obtener_token_acceso = function($credentials) use ($trace) {
            $trace("Generando y obteniendo token de acceso...");

            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');

            $now = time();
            $expires = $now + 3600; // 1 hour

            $unencryptedPayload = json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/drive.file',
                'aud' => 'https://www.googleapis.com/oauth2/v4/token',
                'exp' => $expires,
                'iat' => $now
            ]);
            $payload = rtrim(strtr(base64_encode($unencryptedPayload), '+/', '-_'), '=');
            $trace("Payload JWT generado.");

            $signature = '';
            $private_key = openssl_pkey_get_private($credentials['private_key']);
            if (!$private_key) {
                $trace("Error al obtener la clave privada.");
                return false;
            }

            openssl_sign("$header.$payload", $signature, $private_key, 'SHA256');
            if (PHP_VERSION_ID < 80000) {
                openssl_free_key($private_key);
            }

            if (!$signature) {
                $trace("Error al firmar el JWT.");
                return false;
            }
            $trace("JWT firmado correctamente.");

            $signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $jwt = "$header.$payload.$signature";

            $trace("JWT generado exitosamente.");

            $ch = curl_init('https://www.googleapis.com/oauth2/v4/token');
            if (!$ch) {
                $trace("Error al inicializar cURL.");
                return false;
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion='.$jwt);

            $response = curl_exec($ch);
            if ($response === false) {
                $trace("Error en la solicitud cURL: " . curl_error($ch));
                curl_close($ch);
                return false;
            }

            curl_close($ch);
            $trace("Solicitud cURL completada.");

            $response_data = json_decode($response, true);
            if (isset($response_data['access_token'])) {
                $trace("Token de acceso obtenido correctamente.");
                return $response_data['access_token'];
            } else {
                $trace("Error al obtener el token de acceso: " . json_encode($response_data));
                return false;
            }
        };

        $crear_estructura_carpetas = function($access_token, $root_folder_id, $full_path, $categoria_agrupadora) use ($trace) {
            $trace("Creando estructura de carpetas en Google Drive...");

            $folder_ids = [];
            $folders = explode(' ~ ', $full_path);
            $parent_id = $root_folder_id;
            $categoria_agrupadora_id = null;

            foreach ($folders as $folder) {
                // Escapar las comillas simples en el nombre de la carpeta
                $escaped_folder = str_replace("'", "", $folder);
                // Hacer encoding de URL solo para el valor
                $query = "name='$escaped_folder' and '$parent_id' in parents and mimeType='application/vnd.google-apps.folder'";
                $encoded_query = "https://www.googleapis.com/drive/v3/files?q=".urlencode($query);
                $ch = curl_init($encoded_query);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer $access_token",
                    "Content-Type: application/json"
                ]);

                $response = curl_exec($ch);
                if ($response === false) {
                    $trace("Error en la solicitud cURL: " . curl_error($ch));
                    curl_close($ch);
                    return false;
                }

                $response_data = json_decode($response, true);
                curl_close($ch);

                if (empty($response_data['files'])) {
                    $trace("Carpeta '$escaped_folder' no encontrada. Creando carpeta...");

                    $metadata = json_encode([
                        'name' => $escaped_folder,
                        'mimeType' => 'application/vnd.google-apps.folder',
                        'parents' => [$parent_id]
                    ]);

                    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Authorization: Bearer $access_token",
                        "Content-Type: application/json"
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata);

                    $response = curl_exec($ch);
                    if ($response === false) {
                        $trace("Error en la solicitud cURL: " . curl_error($ch));
                        curl_close($ch);
                        return false;
                    }

                    $response_data = json_decode($response, true);
                    curl_close($ch);

                    if (isset($response_data['id'])) {
                        $trace("Carpeta '$escaped_folder' creada con ID: " . $response_data['id']);
                        $parent_id = $response_data['id'];
                    } else {
                        $trace("Error al crear la carpeta '$escaped_folder': " . json_encode($response_data));
                        return false;
                    }
                } else {
                    $trace("Carpeta '$escaped_folder' encontrada con ID: " . $response_data['files'][0]['id']);
                    $parent_id = $response_data['files'][0]['id'];
                }

                // Verificar si la carpeta actual es la categoría agrupadora
                if ($escaped_folder === $categoria_agrupadora) {
                    $categoria_agrupadora_id = $parent_id;
                }
            }

            return ['final_folder_id' => $parent_id, 'categoria_agrupadora_id' => $categoria_agrupadora_id];
        };

        $subir_csv_a_google_drive = function($access_token, $folder_id, $course, $course_id, $csv_content) use ($trace) {
            $trace("Iniciando la subida del contenido CSV a Google Drive...");

            // Verificar la existencia de la subcarpeta "Anteriores"
            $check_subfolder = function($parent_id) use ($access_token, $trace) {
                $subfolder_name = "Anteriores";
                $query = "name='$subfolder_name' and '$parent_id' in parents and mimeType='application/vnd.google-apps.folder'";
                $ch = curl_init("https://www.googleapis.com/drive/v3/files?q=".urlencode($query));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer $access_token",
                    "Content-Type: application/json"
                ]);

                $response = curl_exec($ch);
                if ($response === false) {
                    $trace("Error en la solicitud cURL: " . curl_error($ch));
                    curl_close($ch);
                    return false;
                }

                $response_data = json_decode($response, true);
                curl_close($ch);

                if (empty($response_data['files'])) {
                    // Crear la subcarpeta "Anteriores" si no existe
                    $metadata = json_encode([
                        'name' => $subfolder_name,
                        'mimeType' => 'application/vnd.google-apps.folder',
                        'parents' => [$parent_id]
                    ]);

                    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Authorization: Bearer $access_token",
                        "Content-Type: application/json"
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata);

                    $response = curl_exec($ch);
                    if ($response === false) {
                        $trace("Error en la solicitud cURL: " . curl_error($ch));
                        curl_close($ch);
                        return false;
                    }

                    $response_data = json_decode($response, true);
                    curl_close($ch);

                    if (isset($response_data['id'])) {
                        $trace("Subcarpeta 'Anteriores' creada con ID: " . $response_data['id']);
                        return $response_data['id'];
                    } else {
                        $trace("Error al crear la subcarpeta 'Anteriores': " . json_encode($response_data));
                        return false;
                    }
                } else {
                    $trace("Subcarpeta 'Anteriores' encontrada con ID: " . $response_data['files'][0]['id']);
                    return $response_data['files'][0]['id'];
                }
            };

            // Copiar archivos existentes a la subcarpeta "Anteriores" y eliminar los originales
            $copy_and_delete_existing_files = function($parent_id, $subfolder_id, $course_shortname, $course_id) use ($access_token, $trace) {
                $query = "'$parent_id' in parents and mimeType!='application/vnd.google-apps.folder'";
                $ch = curl_init("https://www.googleapis.com/drive/v3/files?q=".urlencode($query));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer $access_token",
                    "Content-Type: application/json"
                ]);

                $response = curl_exec($ch);
                if ($response === false) {
                    $trace("Error en la solicitud cURL: " . curl_error($ch));
                    curl_close($ch);
                    return false;
                }

                $response_data = json_decode($response, true);
                curl_close($ch);

                if (!empty($response_data['files'])) {
                    foreach ($response_data['files'] as $file) {
                        $file_id = $file['id'];
                        $file_name = $file['name'];

                        // Verificar si el nombre del archivo comienza con el prefijo correcto
                        $prefix = "{$course_shortname}_{$course_id}_";
                        if (strpos($file_name, $prefix) === 0) {
                            // Copiar el archivo a la subcarpeta "Anteriores"
                            $metadata = json_encode([
                                'parents' => [$subfolder_id]
                            ]);

                            $ch = curl_init("https://www.googleapis.com/drive/v3/files/$file_id/copy");
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                "Authorization: Bearer $access_token",
                                "Content-Type: application/json"
                            ]);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata);

                            $response = curl_exec($ch);
                            if ($response === false) {
                                $trace("Error al copiar el archivo: " . curl_error($ch));
                                curl_close($ch);
                                return false;
                            }

                            $response_data = json_decode($response, true);
                            curl_close($ch);

                            if (isset($response_data['id'])) {
                                $trace("Archivo copiado con ID: " . $response_data['id']);

                                // Eliminar el archivo original
                                $ch = curl_init("https://www.googleapis.com/drive/v3/files/$file_id");
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                    "Authorization: Bearer $access_token"
                                ]);

                                $response = curl_exec($ch);
                                if ($response === false) {
                                    $trace("Error al eliminar el archivo: " . curl_error($ch));
                                    curl_close($ch);
                                    return false;
                                }

                                curl_close($ch);
                                $trace("Archivo original eliminado con ID: $file_id");
                            } else {
                                $trace("Error al copiar el archivo: " . json_encode($response_data));
                                return false;
                            }
                        }
                    }
                } else {
                    $trace("No hay archivos existentes en la carpeta de destino.");
                }
            };

            // Verificar y crear la subcarpeta "Anteriores" si es necesario
            $subfolder_id = $check_subfolder($folder_id);
            if ($subfolder_id === false) {
                return false;
            }

            // Copiar y eliminar archivos existentes
            $copy_and_delete_existing_files($folder_id, $subfolder_id, $course->shortname, $course_id);

            // Subir el nuevo archivo a la carpeta de destino
            $boundary = uniqid();
            $delimiter = '-------------' . $boundary;

            $actual_date = date('Ymd_His');
            $file_name = "{$course->shortname}_{$course_id}_{$actual_date}.csv";

            $post_data = "--$delimiter\r\n"
                . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
                . json_encode(['name' => $file_name, 'parents' => [$folder_id]]) . "\r\n"
                . "--$delimiter\r\n"
                . "Content-Type: text/csv\r\n\r\n"
                . $csv_content . "\r\n"
                . "--$delimiter--";

            $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
            if (!$ch) {
                $trace("Error al inicializar cURL.");
                return false;
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $access_token",
                "Content-Type: multipart/related; boundary=$delimiter"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

            $response = curl_exec($ch);
            if ($response === false) {
                $trace("Error en la solicitud cURL: " . curl_error($ch));
                curl_close($ch);
                return false;
            }

            curl_close($ch);
            $trace("Solicitud cURL completada.");

            $response_data = json_decode($response, true);
            if (isset($response_data['id'])) {
                $trace("Archivo subido a Google Drive con ID: " . $response_data['id']);
            } else {
                $trace("Error al subir el archivo a Google Drive: " . json_encode($response_data));
            }
        };

        // Obtener estructura de carpetas para un curso
        $obtener_estructura_carpetas = function($course_id) use ($DB) {
            $sql = "WITH RECURSIVE category_hierarchy AS (
                        SELECT
                            id,
                            name,
                            parent,
                            path,
                            name AS full_path
                        FROM
                            {course_categories}
                        WHERE
                            parent = 0
                        UNION ALL
                        SELECT
                            c.id,
                            c.name,
                            c.parent,
                            c.path,
                            CONCAT(ch.full_path, ' ~ ', c.name) AS full_path
                        FROM
                            {course_categories} c
                        INNER JOIN
                            category_hierarchy ch ON c.parent = ch.id
                    )
                    SELECT
                        CONCAT(ch.full_path, ' ~ ', c.fullname) AS estructura_carpetas
                    FROM
                        {course} c
                    INNER JOIN
                        category_hierarchy ch ON c.category = ch.id
                    WHERE
                        c.id = :courseid
                    ORDER BY
                        ch.full_path, c.fullname";

            return $DB->get_record_sql($sql, ['courseid' => $course_id]);
        };

        $cursos_a_procesar = $obtener_cursos_a_procesar($config_data);

        if (empty($cursos_a_procesar)) {
            $trace("No hay cursos a procesar según las condiciones establecidas.");
            $imprimir_fin();
            return;
        }

        if (!is_array($cursos_a_procesar)) {
            $trace("Error: cursos_a_procesar no es un array.");
            $trace($cursos_a_procesar);
            $imprimir_fin();
            return;
        }

        $credentials = $leer_credenciales($credentials_path);
        if (!$credentials) {
            $imprimir_fin();
            return;
        }

        $access_token = $obtener_token_acceso($credentials);
        if (!$access_token) {
            $imprimir_fin();
            return;
        }

        foreach ($cursos_a_procesar as $course_id) {
            $course = $DB->get_record('course', ['id' => $course_id], 'shortname');

            $prefijos_grupos = '';
            $categoria_agrupadora = '';
            foreach ($config_data->courses as $course_config) {
                if ($course_config->course_id == $course_id) {
                    $prefijos_grupos = $course_config->prefijos_grupos;
                    $categoria_agrupadora = $course_config->categoria_agrupadora;
                    break;
                }
            }

            $results = $obtener_calificaciones($course_id, $prefijos_grupos);
            if (!$results) {
                continue;
            }

            $csv_content = $generar_csv($results);

            $carpeta = $obtener_estructura_carpetas($course_id);
            if (!$carpeta) {
                continue;
            }

            $folder_ids = $crear_estructura_carpetas($access_token, $root_folder_id, $carpeta->estructura_carpetas, $categoria_agrupadora);
            if (!$folder_ids) {
                continue;
            }

            $folder_id = $folder_ids['final_folder_id'];
            $categoria_agrupadora_id = $folder_ids['categoria_agrupadora_id'];

            $subir_csv_a_google_drive($access_token, $folder_id, $course, $course_id, $csv_content);

            // Subir a la carpeta de categoría agrupadora si existe
            if ($categoria_agrupadora_id) {
                $subir_csv_a_google_drive($access_token, $categoria_agrupadora_id, $course, $course_id, $csv_content);
            }
        }

        $imprimir_fin();
    }




    public static function get_configuration_json() {
        global $DB, $CFG;
        $ret = null;
        $config_path = $CFG->dataroot . '/exportanotas_configurations.json';
        if (file_exists($config_path)) {
            $ret = json_decode(file_get_contents($config_path));
        } 
        return $ret;
    }

    public static function get_seleccion_de_notas_course($course_id) {
        $ret = null;
        $config_data = exportar_calificaciones_task::get_configuration_json();
        if($config_data){
            if(isset($course_id) && isset($config_data->courses)){
                $courses = $config_data->courses;
                foreach($courses as $c){
                    if($course_id == $c->course_id) {
                        if(isset($c->seleccion_de_notas)){
                            $ret = $c->seleccion_de_notas;
                        }
                        break;
                    }
                }
            }
        }
        return $ret;
    }

    public static function get_fixed_grade_items_to_export($course_id) {
        $ret = array();
        $fixed = exportar_calificaciones_task::get_default_grade_items();
        $items = exportar_calificaciones_task::get_seleccion_de_notas_course($course_id);
        //Convertir a un array el objeto
        $array_seleccion_de_notas = get_object_vars($items);
        if(sizeof($array_seleccion_de_notas) > 0) {
            foreach($fixed as $f) {
                foreach( $array_seleccion_de_notas as $k1 => $val1 ) {
                    $id = str_replace('grade_item_', '', $k1);
                    if($val1 == '1' && $id == $f->id) {
                        array_push($ret, $f);
                        break;
                    }
                }
            }
        }

        return $ret;
    }
    
    public static function get_default_grade_items() {
        //is_set => indica si esta configurada la nota en los items de calificaciones
        //is_fixed => indica que este item de calificacion es un item de calificacion default(fijo) (que todos los cursos van a tener este item de calificación)
        $ret[] = (object) array(
            'id' => 'FJU', 
            'gradeitemid' => 'FJU', 
            'itemname' => 'FJU: Final Julio', 
            'grademax' => 10,
            'gradepass' => 4,
            'sortorder' => null,
            'section' => null,
            'position' => null,
            'cmid' => null,
            'is_fixed' => true,
            'is_set' => false, 
            'is_fixed' => true,
            'is_averageable' => false,
            'courseid' => null,
            'itemmodule' => 'exportanotas',
            'itemtype' => 'manual',
            'gradetype' => 1,
            'grademin' => 1,
        );
        $ret[] = (object) array(
            'id' => 'FD1', 
            'gradeitemid' => 'FD1', 
            'itemname' => 'FD1: Final Diciembre - Primer Llamado',
            'grademax' => 10,
            'gradepass' => 4,
            'sortorder' => null,
            'section' => null,
            'position' => null,
            'cmid' => null,
            'is_fixed' => true,
            'is_set' => false,  
            'is_fixed' => true,
            'is_averageable' => false,
            'courseid' => null,
            'itemmodule' => 'exportanotas',
            'itemtype' => 'manual',
            'gradetype' => 1,
            'grademin' => 1,
        );
        $ret[] = (object) array(
            'id' => 'FD2', 
            'gradeitemid' => 'FD2', 
            'itemname' => 'FD2: Final Diciembre - Segundo Llamado',
            'grademax' => 10,
            'gradepass' => 4,
            'sortorder' => null,
            'section' => null,
            'position' => null,
            'cmid' => null,
            'is_fixed' => true,
            'is_set' => false, 
            'is_fixed' => true,
            'is_averageable' => false,
            'courseid' => null,
            'itemmodule' => 'exportanotas',
            'itemtype' => 'manual',
            'gradetype' => 1,
            'grademin' => 1,
        );
        $ret[] = (object) array(
            'id' => 'FF1', 
            'gradeitemid' => 'FF1', 
            'itemname' => 'FF1: Final Febrero - Primer Llamado', 
            'grademax' => 10,
            'gradepass' => 4,
            'sortorder' => null,
            'section' => null,
            'position' => null,
            'cmid' => null,
            'is_fixed' => true,
            'is_set' => false, 
            'is_fixed' => true,
            'is_averageable' => false,
            'courseid' => null,
           'itemmodule' => 'exportanotas',
           'itemtype' => 'manual',
           'gradetype' => 1,
            'grademin' => 1,
        );
        $ret[] = (object) array(
            'id' => 'FF2', 
            'gradeitemid' => 'FF2', 
            'itemname' => 'FF2: Final Febrero - Segundo Llamado', 
            'grademax' => 10,
            'gradepass' => 4,
            'sortorder' => null,
            'section' => null,
            'position' => null,
            'cmid' => null,
            'is_fixed' => true,
            'is_set' => false, 
            'is_fixed' => true,
            'is_averageable' => false,
            'courseid' => null,
            'itemmodule' => 'exportanotas',
            'itemtype' => 'manual',
            'gradetype' => 1,
            'grademin' => 1,
        ); 
        $ret[] = (object) array(
            'id' => 'NFC',
            'gradeitemid' => 'NFC',
            'itemname' => 'NFC: Nota Final de Cursada',
            'grademax' => 10,
            'gradepass' => 4,
            'sortorder' => null,
            'section' => null,
            'position' => null,
            'cmid' => null,
            'is_fixed' => true,
            'is_set' => false, 
            'is_fixed' => true,
            'is_averageable' => true,
            'courseid' => null,
            'itemmodule' => 'exportanotas',
            'itemtype' => 'manual',
            'gradetype' => 1,
            'grademin' => 1,
        );

        return $ret;
    }

    public static function get_sql_notas_seleccionadas($seleccion_de_notas) {
        $sql_notas = '';
        $ret = '';
        //Convertir a un array el objeto
        $array_seleccion_de_notas = get_object_vars($seleccion_de_notas);
        if(sizeof($array_seleccion_de_notas) > 0) {
            foreach( $array_seleccion_de_notas as $k => $val ) {
                if($val == '1') {
                    $id = str_replace('grade_item_', '', $k);
                    if(is_numeric($id)) {
                        $sql_notas .= $id . ",";
                    }
                }
            }
        }

        $ret = substr($sql_notas, 0, -1);

        return $ret;
    }

    public static function get_sql_where_seleccion_de_notas($seleccion_de_notas) {
            
        $sql_where_notas = '';
        
        $sql_notas = exportar_calificaciones_task::get_sql_notas_seleccionadas($seleccion_de_notas);

        if(!empty($sql_notas)) {
            $sql_where_notas = " gi.id IN ( {$sql_notas} ) ";
        } else {
            //Para cuando no hay notas configuradas
            $sql_where_notas = " gi.id IS NULL ";
        }

        return $sql_where_notas;

    }


    public static function get_sql_grade_items_for_selectable_fixed_grade_item( $course_id, $fixed_grade_item_id ) {

        $ret = '';

        $seleccion_de_notas = exportar_calificaciones_task::get_seleccion_de_notas_course($course_id);

        $id_item = null;

        $sql_notas = '';

        //Convertir a un array el objeto
        $array_seleccion_de_notas = get_object_vars($seleccion_de_notas);
        if(sizeof($array_seleccion_de_notas) > 0) {
            foreach( $array_seleccion_de_notas as $k => $val ) {
                if($k == "config_grade_item_$fixed_grade_item_id") {
                    $id_item = $val;
                    break;
                }
            }
        }

        if(isset($id_item)) {
            if(!empty($id_item)){
                $ret = $id_item;
            }
        }

        return $ret;
    }


    public static function get_sql_grade_items_for_avg_fixed_grade_item( $course_id, $fixed_grade_item_id ) {

        $ret = '';

        $seleccion_de_notas = exportar_calificaciones_task::get_seleccion_de_notas_course($course_id);

        $config = null;
        $sql_notas = '';

        //Convertir a un array el objeto
        $array_seleccion_de_notas = get_object_vars($seleccion_de_notas);
        if(sizeof($array_seleccion_de_notas) > 0) {
            foreach( $array_seleccion_de_notas as $k => $val ) {
                if($k == "average_config_grade_item_$fixed_grade_item_id") {
                    $config = $val;
                    break;
                }
            }
        }
        if(isset($config)) {
            $items = exportar_calificaciones_task::get_sql_notas_seleccionadas($config);
            if(!empty($items)){
                $ret = "( {$items} )";
            } else {
                $ret = "( NULL )";
            }
        }

        return $ret;
    }

}

?>
