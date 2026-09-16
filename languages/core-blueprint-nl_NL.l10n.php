<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 * Combines the base catalog with current feature translations.
 */
$catalog = require __DIR__ . '/base/core-blueprint-nl_NL.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
        'Design loaded' => 'Ontwerp geladen',
        'Loading design…' => 'Ontwerp laden…',
        'The selected design could not be loaded.' => 'Het geselecteerde ontwerp kon niet worden geladen.',
        'The selected mail template is incomplete.' => 'De geselecteerde e-mailsjabloon is onvolledig.',
        'The SVG replacement could not be sanitized safely.' => 'Het vervangende SVG-bestand kon niet veilig worden opgeschoond.',
        'The sanitized SVG replacement no longer matches the expected file type.' => 'Het opgeschoonde vervangende SVG-bestand komt niet meer overeen met het verwachte bestandstype.',
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
        'Validate mapping' => 'Koppeling valideren',
    ]
);

return $catalog;
