<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 *
 * The preserved release baseline remains byte-identical in languages/base/.
 * This wrapper layers the current feature strings on top without rewriting
 * the large generated catalog through a lossy transport boundary.
 */
$catalog = require __DIR__ . '/base/core-blueprint-es_ES.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
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
        'Validate mapping.' => 'Validar mapeo.',
    ]
);

return $catalog;
