<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 * Combines the base catalog with current feature translations.
 */
$catalog = require __DIR__ . '/base/core-blueprint-es_ES.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
        'Design loaded' => 'Diseño cargado',
        'Loading design…' => 'Cargando diseño…',
        'The selected design could not be loaded.' => 'No se pudo cargar el diseño seleccionado.',
        'The selected mail template is incomplete.' => 'La plantilla de correo electrónico seleccionada está incompleta.',
        'The SVG replacement could not be sanitized safely.' => 'No se pudo sanear de forma segura el archivo SVG de reemplazo.',
        'The sanitized SVG replacement no longer matches the expected file type.' => 'El archivo SVG de reemplazo saneado ya no coincide con el tipo de archivo esperado.',
        'Auto-match' => 'Correspondencia automática',
        'Choose a source file to begin mapping.' => 'Elige un archivo de origen para comenzar el mapeo.',
        'Choose source file' => 'Elegir archivo de origen',
        'Constant' => 'Constante',
        'Constant value' => 'Valor constante',
        'Data Exchange entity' => 'Entidad de Data Exchange',
        'Data Mapper' => 'Data Mapper',
        'Data Mapper details' => 'Detalles de Data Mapper',
        'Direct' => 'Directo',
        'Field mapping' => 'Mapeo de campos',
        'Ignore' => 'Ignorar',
        'Inspecting source file…' => 'Inspeccionando el archivo de origen…',
        'Map source fields to a target structure, validate the result, and review what will happen before data moves.' => 'Mapea los campos de origen a una estructura de destino, valida el resultado y revisa qué ocurrirá antes de mover los datos.',
        'Mapping' => 'Mapeo',
        'Mapping is complete.' => 'El mapeo está completo.',
        'Mapping needs attention.' => 'El mapeo requiere atención.',
        'No validated preview is available yet.' => 'Todavía no hay una vista previa validada disponible.',
        'Preview' => 'Vista previa',
        'Preview export' => 'Vista previa de exportación',
        'Provides versioned import and export of extension-owned data through the Core Blueprint Data Exchange Foundation.' => 'Proporciona importación y exportación versionadas de datos propiedad de extensiones mediante Core Blueprint Data Exchange Foundation.',
        'Required target fields are still unmapped.' => 'Los campos de destino obligatorios aún no están mapeados.',
        'Search fields' => 'Buscar campos',
        'Select a field mapping to inspect it.' => 'Selecciona un mapeo de campo para inspeccionarlo.',
        'Source file' => 'Archivo de origen',
        'Source file selected.' => 'Archivo de origen seleccionado.',
        'Target field' => 'Campo de destino',
        'Transform' => 'Transformación',
        'Validate mapping' => 'Validar mapeo',
        'I understand that replacing this media file changes the attachment\'s stored files and that I am responsible for having a recent backup or other recovery option available.' => 'Entiendo que reemplazar este archivo multimedia modifica los archivos almacenados del adjunto y que soy responsable de disponer de una copia de seguridad reciente u otra opción de recuperación.',
        'Generated sizes, caches, themes, plugins and external references may affect the result.' => 'Los tamaños generados, las cachés, los temas, los plugins y las referencias externas pueden afectar al resultado.',
        'Confirm your responsibility for backup and recovery before replacing the media file.' => 'Confirma tu responsabilidad sobre la copia de seguridad y la recuperación antes de reemplazar el archivo multimedia.',
        'I understand that importing this schema changes this site\'s Content Models configuration and that I am responsible for having a recent backup or other recovery option available.' => 'Entiendo que importar este esquema modifica la configuración de Content Models de este sitio y que soy responsable de disponer de una copia de seguridad reciente u otra opción de recuperación.',
        'Imported definitions can affect registered post types, taxonomies, Option Pages and fields. Existing definitions may be replaced when overwrite is selected.' => 'Las definiciones importadas pueden afectar a tipos de contenido, taxonomías, Option Pages y campos registrados. Las definiciones existentes pueden sustituirse cuando se selecciona sobrescribir.',
        'Confirm your responsibility for backup and recovery before importing the Content Models schema.' => 'Confirma tu responsabilidad sobre la copia de seguridad y la recuperación antes de importar el esquema de Content Models.',
        'I understand that applying this plan transfers the selected WordPress schema registrations to Core Blueprint and that I am responsible for having a recent backup or other recovery option available.' => 'Entiendo que aplicar este plan transfiere a Core Blueprint los registros de esquema de WordPress seleccionados y que soy responsable de disponer de una copia de seguridad reciente u otra opción de recuperación.',
        'The original registrar must remain disabled after adoption. Plugins, themes and custom code may affect the resulting runtime.' => 'El origen de registro original debe permanecer desactivado después de la adopción. Los plugins, los temas y el código personalizado pueden afectar al funcionamiento resultante.',
        'Confirm your responsibility for backup and recovery before applying the WordPress import plan.' => 'Confirma tu responsabilidad sobre la copia de seguridad y la recuperación antes de aplicar el plan de importación de WordPress.',
        'I understand that preserving snippet IDs can replace existing managed snippet code and metadata and that I am responsible for having a recent backup or other recovery option available.' => 'Entiendo que conservar los ID de snippets puede reemplazar código y metadatos de snippets gestionados existentes y que soy responsable de disponer de una copia de seguridad reciente u otra opción de recuperación.',
        'Imported snippets remain disabled, but existing snippets with matching IDs can be overwritten.' => 'Los snippets importados permanecen desactivados, pero los snippets existentes con ID coincidentes pueden sobrescribirse.',
        'Confirm your responsibility for backup and recovery before restoring snippets with preserved IDs.' => 'Confirma tu responsabilidad sobre la copia de seguridad y la recuperación antes de restaurar snippets conservando sus ID.',
        'I understand that this import will overwrite existing Notes and that I am responsible for having a recent backup or other recovery option available.' => 'Entiendo que esta importación sobrescribirá Notes existentes y que soy responsable de disponer de una copia de seguridad reciente u otra opción de recuperación.',
        'Only Notes explicitly set to Overwrite existing are replaced.' => 'Solo se reemplazan las Notes configuradas explícitamente como Sobrescribir existente.',
        'Confirm your responsibility for backup and recovery before overwriting existing Notes.' => 'Confirma tu responsabilidad sobre la copia de seguridad y la recuperación antes de sobrescribir Notes existentes.',
        'I understand that restoring this quarantined item changes the site\'s active filesystem and that I am responsible for having a recent backup or other recovery option available.' => 'Entiendo que restaurar este elemento en cuarentena modifica el sistema de archivos activo del sitio y que soy responsable de disponer de una copia de seguridad reciente u otra opción de recuperación.',
        'The restore is refused if the original path is occupied or the quarantined payload no longer matches its evidence.' => 'La restauración se rechaza si la ruta original está ocupada o si la carga en cuarentena ya no coincide con sus evidencias.',
        'Confirm your responsibility for backup and recovery before restoring the quarantined item.' => 'Confirma tu responsabilidad sobre la copia de seguridad y la recuperación antes de restaurar el elemento en cuarentena.',
        'Media Replace: backup and recovery responsibility acknowledged' => 'Media Replace: responsabilidad de copia de seguridad y recuperación confirmada',
        'Content Models: schema import backup and recovery responsibility acknowledged' => 'Content Models: responsabilidad de copia de seguridad y recuperación confirmada para la importación del esquema',
        'Content Models: native import backup and recovery responsibility acknowledged' => 'Content Models: responsabilidad de copia de seguridad y recuperación confirmada para la importación nativa',
        'Snippets: restore backup and recovery responsibility acknowledged' => 'Snippets: responsabilidad de copia de seguridad y recuperación confirmada para la restauración',
        'Notes: import overwrite backup and recovery responsibility acknowledged' => 'Notes: responsabilidad de copia de seguridad y recuperación confirmada para la importación con sobrescritura',
        'Core Scanner: quarantine restore backup and recovery responsibility acknowledged' => 'Core Scanner: responsabilidad de copia de seguridad y recuperación confirmada para la restauración desde cuarentena',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-es_ES.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
