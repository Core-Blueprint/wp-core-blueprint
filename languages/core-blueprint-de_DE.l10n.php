<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 * Combines the base catalog with current feature translations.
 */
$catalog = require __DIR__ . '/base/core-blueprint-de_DE.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
        'Design loaded' => 'Design geladen',
        'Loading design…' => 'Design wird geladen…',
        'The selected design could not be loaded.' => 'Das ausgewählte Design konnte nicht geladen werden.',
        'The selected mail template is incomplete.' => 'Die ausgewählte E-Mail-Vorlage ist unvollständig.',
        'The SVG replacement could not be sanitized safely.' => 'Die SVG-Ersatzdatei konnte nicht sicher bereinigt werden.',
        'The sanitized SVG replacement no longer matches the expected file type.' => 'Die bereinigte SVG-Ersatzdatei entspricht nicht mehr dem erwarteten Dateityp.',
        'Auto-match' => 'Automatisch zuordnen',
        'Choose a source file to begin mapping.' => 'Wähle eine Quelldatei aus, um mit der Zuordnung zu beginnen.',
        'Choose source file' => 'Quelldatei auswählen',
        'Constant' => 'Konstante',
        'Constant value' => 'Konstanter Wert',
        'Data Exchange entity' => 'Data-Exchange-Entität',
        'Data Mapper' => 'Data Mapper',
        'Data Mapper details' => 'Data-Mapper-Details',
        'Direct' => 'Direkt',
        'Field mapping' => 'Feldzuordnung',
        'Ignore' => 'Ignorieren',
        'Inspecting source file…' => 'Quelldatei wird geprüft…',
        'Map source fields to a target structure, validate the result, and review what will happen before data moves.' => 'Ordne Quellfelder einer Zielstruktur zu, validiere das Ergebnis und prüfe, was geschieht, bevor Daten übertragen werden.',
        'Mapping' => 'Zuordnung',
        'Mapping is complete.' => 'Die Zuordnung ist vollständig.',
        'Mapping needs attention.' => 'Die Zuordnung erfordert Aufmerksamkeit.',
        'No validated preview is available yet.' => 'Es ist noch keine validierte Vorschau verfügbar.',
        'Preview' => 'Vorschau',
        'Preview export' => 'Exportvorschau',
        'Provides versioned import and export of extension-owned data through the Core Blueprint Data Exchange Foundation.' => 'Ermöglicht versionierten Import und Export von Daten im Besitz von Erweiterungen über die Core Blueprint Data Exchange Foundation.',
        'Required target fields are still unmapped.' => 'Erforderliche Zielfelder sind noch nicht zugeordnet.',
        'Search fields' => 'Felder durchsuchen',
        'Select a field mapping to inspect it.' => 'Wähle eine Feldzuordnung aus, um sie zu prüfen.',
        'Source file' => 'Quelldatei',
        'Source file selected.' => 'Quelldatei ausgewählt.',
        'Target field' => 'Zielfeld',
        'Transform' => 'Transformation',
        'Validate mapping' => 'Zuordnung validieren',
    ]
);

return $catalog;
