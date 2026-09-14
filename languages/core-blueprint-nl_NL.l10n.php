<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 *
 * The preserved release baseline remains byte-identical in languages/base/.
 * This wrapper layers the current feature strings on top without rewriting
 * the large generated catalog through a lossy transport boundary.
 */
$catalog = require __DIR__ . '/base/core-blueprint-nl_NL.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
        'Auto-match' => 'Automatisch koppelen',
        'Choose a source file to begin mapping.' => 'Kies een bronbestand om met koppelen te beginnen.',
        'Choose source file' => 'Bronbestand kiezen',
        'Constant' => 'Constante',
        'Constant value' => 'Constante waarde',
        'Data Exchange entity' => 'Data Exchange-entiteit',
        'Data Mapper' => 'Data Mapper',
        'Data Mapper details' => 'Data Mapper-details',
        'Direct' => 'Direct',
        'Field mapping' => 'Veldkoppeling',
        'Ignore' => 'Negeren',
        'Inspecting source file…' => 'Bronbestand controleren…',
        'Map source fields to a target structure, validate the result, and review what will happen before data moves.' => 'Koppel bronvelden aan een doelstructuur, valideer het resultaat en bekijk wat er gebeurt voordat gegevens worden verplaatst.',
        'Mapping' => 'Koppeling',
        'Mapping is complete.' => 'De koppeling is compleet.',
        'Mapping needs attention.' => 'De koppeling vereist aandacht.',
        'No validated preview is available yet.' => 'Er is nog geen gevalideerd voorbeeld beschikbaar.',
        'Preview' => 'Voorbeeld',
        'Preview export' => 'Exportvoorbeeld',
        'Provides versioned import and export of extension-owned data through the Core Blueprint Data Exchange Foundation.' => 'Biedt versiebeheer voor import en export van gegevens die eigendom zijn van extensies via de Core Blueprint Data Exchange Foundation.',
        'Required target fields are still unmapped.' => 'Vereiste doelvelden zijn nog niet gekoppeld.',
        'Search fields' => 'Velden zoeken',
        'Select a field mapping to inspect it.' => 'Selecteer een veldkoppeling om deze te bekijken.',
        'Source file' => 'Bronbestand',
        'Source file selected.' => 'Bronbestand geselecteerd.',
        'Target field' => 'Doelveld',
        'Transform' => 'Transformatie',
        'Validate mapping.' => 'Koppeling valideren.',
    ]
);

return $catalog;
