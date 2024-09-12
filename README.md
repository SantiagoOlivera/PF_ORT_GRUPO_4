# Plugin Exporta Notas

## Índice

1. [Instalación del Plugin](#instalación-del-plugin)
2. [Configuración de la actividad 'Exporta Notas'](#configuración-de-la-actividad-exporta-notas)
3. [Asignación del Rol 'Administrador de Configuración de Drive'](#asignación-del-rol-administrador-de-configuración-de-drive)
4. [Configuración Google Cloud y Google Drive](#configuración-google-cloud-y-google-drive)

## Instalación del Plugin

1. **Instalar el archivo .zip:**
    - Accede a la administración del sitio: Inicia sesión en el panel de administración de tu sitio.
    - Navega a la sección de extensiones: En el menú principal selecciona la opción "Extensiones" y luego "Instalar complementos".
    - Sube el archivo .zip: Arrastra el archivo .zip del plugin a la zona de carga o selecciónalo manualmente desde tu computadora.
    - Instala el plugin: Sigue las indicaciones en pantalla para completar la instalación del plugin.

## Configuración de la actividad 'Exporta Notas'

1. **Acceder al curso:**
    - Ingresa al curso donde deseas agregar la actividad.
    
2. **Agregar la actividad 'Exporta Notas':**
    - Haz clic en "Agregar actividad o recurso".
    - Selecciona la actividad 'Exporta Notas'.

3. **Configurar la actividad:**
    - Una vez agregada la actividad al curso, se deben configurar los campos del formulario.
    - **Nombre de exporta notas:** Coloca el nombre deseado.
    - **Exportación de notas:** Configura la frecuencia de exportación del CSV con los campos de minuto, hora, día, mes y día de la semana. Si habilitas el campo "Rango de fechas", se mostrarán los campos "Fecha de inicio" y "Fecha de fin" para definir el rango de fechas en el que se exportarán las notas del curso.
    - **Habilitar categoría agrupadora:** Si seleccionas esta opción, se mostrará un menú desplegable con la lista de categorías del curso. Al seleccionar una categoría, el CSV se exportará también en la ruta de dicha categoría.
    - **Prefijos de grupo:** Utiliza este campo para filtrar el CSV por cursos específicos. Por ejemplo, para exportar solo los cursos de Belgrano coloca las letras "be" seguidas de una coma.
    - **Modo depuración:** Si activas esta opción, al ejecutar la actividad se mostrarán los logs del código en ejecución.
    - **Credenciales e ID de Google Drive:** Agrega las credenciales en formato JSON y el ID de la carpeta de Google Drive donde se exportarán las notas.
    - **Visibilidad de campos de configuración:** Los campos para ingresar las credenciales y el ID de la carpeta de Google Drive solo serán visibles para los usuarios con el rol de 'Administrador de Configuración de Drive'. Los usuarios que no tengan este rol no verán estos campos; estos se autocompletarán con los datos ingresados previamente por el administrador.

## Asignación del Rol 'Administrador de Configuración de Drive'

1. **Acceder a la administración del sitio:**
    - Ir a la sección "Administración del sitio".
    
2. **Navegar a usuarios:**
    - Seleccionar "Usuarios" en el menú.
    - Luego elegir "Asignar roles de sistema".

3. **Seleccionar el rol 'Administrador de Configuración de Drive':**
    - En la lista de roles disponibles, seleccionar el rol 'Administrador de Configuración de Drive'.

4. **Asignar usuarios al rol:**
    - En el recuadro de "Usuarios potenciales" se listarán todos los usuarios registrados.
    - Elegir de esa lista a los usuarios a quienes desees asignar el rol.
    - Hacer clic en "Agregar" para completar la asignación.

## Configuración Google Cloud y Google Drive

1. **Acceder a https://console.cloud.google.com**
    - Crear un proyecto que nos permitirá acceder a los archivos de Google Drive.
    - Haz clic en “Selecciona un proyecto”.
    - Haz clic en “Proyecto nuevo”.
    - Escribe el nombre del proyecto y selecciona el botón crear.
    - Selecciona el proyecto anteriormente creado.
    - Luego selecciona del menú lateral “APIs y servicios” -> “APIs y servicios habilitados”.
    - Selecciona “Biblioteca”.
    - Luego selecciona la opción “Google Drive API”.
    - Habilita la API.
    - Luego selecciona la opción “Credenciales”.
    - Haz clic en “Crear credenciales” -> “Cuenta de servicio”.
    - Crea una cuenta para poder acceder a la API.
    - En Nombre de la cuenta de servicio, coloca el nombre de la cuenta que se quiere crear.
    - Presiona en Crear y continuar.
    - Luego selecciona el rol que tendrá la cuenta, selecciona “Propietario” y presiona continuar.
    - Una vez hecho esto, presiona en “Listo” para finalizar la creación de la cuenta de servicios.
    - Haz clic en la cuenta creada.
    - Ve a la sección “Claves”.
    - Haz clic en “Agregar clave” -> “Crear clave nueva”.
    - Selecciona como Tipo de clave “JSON” -> “Crear”.
    - Esto descargará un archivo con extensión .json.
    - Luego ve al Drive de la cuenta de Google y crea la carpeta donde se exportarán las notas.
    - Posteriormente, posiciónate en dicha carpeta y haz clic en el nombre de la carpeta -> “compartir”.
    - Comparte la carpeta de Drive con el correo electrónico que se había creado anteriormente en la API.
    - Luego copia el ID de la carpeta de Google Drive. Este se puede obtener posicionándose en la carpeta de Drive creada y se encontrará en la parte final de la URL.
    - El archivo .json descargado y el ID de la carpeta de Google Drive son los datos que se deben ingresar en la pantalla de configuración de la actividad en Moodle.
    - El archivo JSON descargado súbelo en el campo: “Credenciales (json)”.
    - El ID de la carpeta de Drive pégalo en el campo: “ID de Carpeta Google Drive”.
