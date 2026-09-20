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
        'I understand that replacing this media file changes the attachment\'s stored files and that I am responsible for having a recent backup or other recovery option available.' => 'Ich verstehe, dass das Ersetzen dieser Mediendatei die gespeicherten Dateien des Anhangs ändert und dass ich dafür verantwortlich bin, eine aktuelle Sicherung oder eine andere Wiederherstellungsoption verfügbar zu haben.',
        'Generated sizes, caches, themes, plugins and external references may affect the result.' => 'Erzeugte Größen, Caches, Themes, Plugins und externe Referenzen können das Ergebnis beeinflussen.',
        'Confirm your responsibility for backup and recovery before replacing the media file.' => 'Bestätige deine Verantwortung für Sicherung und Wiederherstellung, bevor du die Mediendatei ersetzt.',
        'I understand that importing this schema changes this site\'s Content Models configuration and that I am responsible for having a recent backup or other recovery option available.' => 'Ich verstehe, dass der Import dieses Schemas die Content-Models-Konfiguration dieser Website ändert und dass ich dafür verantwortlich bin, eine aktuelle Sicherung oder eine andere Wiederherstellungsoption verfügbar zu haben.',
        'Imported definitions can affect registered post types, taxonomies, Option Pages and fields. Existing definitions may be replaced when overwrite is selected.' => 'Importierte Definitionen können registrierte Beitragstypen, Taxonomien, Option Pages und Felder beeinflussen. Bestehende Definitionen können ersetzt werden, wenn Überschreiben ausgewählt ist.',
        'Confirm your responsibility for backup and recovery before importing the Content Models schema.' => 'Bestätige deine Verantwortung für Sicherung und Wiederherstellung, bevor du das Content-Models-Schema importierst.',
        'I understand that applying this plan transfers the selected WordPress schema registrations to Core Blueprint and that I am responsible for having a recent backup or other recovery option available.' => 'Ich verstehe, dass das Anwenden dieses Plans die ausgewählten WordPress-Schemaregistrierungen an Core Blueprint überträgt und dass ich dafür verantwortlich bin, eine aktuelle Sicherung oder eine andere Wiederherstellungsoption verfügbar zu haben.',
        'The original registrar must remain disabled after adoption. Plugins, themes and custom code may affect the resulting runtime.' => 'Die ursprüngliche Registrierungsquelle muss nach der Übernahme deaktiviert bleiben. Plugins, Themes und benutzerdefinierter Code können die resultierende Laufzeit beeinflussen.',
        'Confirm your responsibility for backup and recovery before applying the WordPress import plan.' => 'Bestätige deine Verantwortung für Sicherung und Wiederherstellung, bevor du den WordPress-Importplan anwendest.',
        'I understand that preserving snippet IDs can replace existing managed snippet code and metadata and that I am responsible for having a recent backup or other recovery option available.' => 'Ich verstehe, dass das Beibehalten von Snippet-IDs vorhandenen verwalteten Snippet-Code und Metadaten ersetzen kann und dass ich dafür verantwortlich bin, eine aktuelle Sicherung oder eine andere Wiederherstellungsoption verfügbar zu haben.',
        'Imported snippets remain disabled, but existing snippets with matching IDs can be overwritten.' => 'Importierte Snippets bleiben deaktiviert, vorhandene Snippets mit übereinstimmenden IDs können jedoch überschrieben werden.',
        'Confirm your responsibility for backup and recovery before restoring snippets with preserved IDs.' => 'Bestätige deine Verantwortung für Sicherung und Wiederherstellung, bevor du Snippets mit beibehaltenen IDs wiederherstellst.',
        'I understand that this import will overwrite existing Notes and that I am responsible for having a recent backup or other recovery option available.' => 'Ich verstehe, dass dieser Import vorhandene Notes überschreibt und dass ich dafür verantwortlich bin, eine aktuelle Sicherung oder eine andere Wiederherstellungsoption verfügbar zu haben.',
        'Only Notes explicitly set to Overwrite existing are replaced.' => 'Nur Notes, die ausdrücklich auf Vorhandene überschreiben gesetzt sind, werden ersetzt.',
        'Confirm your responsibility for backup and recovery before overwriting existing Notes.' => 'Bestätige deine Verantwortung für Sicherung und Wiederherstellung, bevor du vorhandene Notes überschreibst.',
        'I understand that restoring this quarantined item changes the site\'s active filesystem and that I am responsible for having a recent backup or other recovery option available.' => 'Ich verstehe, dass die Wiederherstellung dieses Quarantäne-Elements das aktive Dateisystem der Website ändert und dass ich dafür verantwortlich bin, eine aktuelle Sicherung oder eine andere Wiederherstellungsoption verfügbar zu haben.',
        'The restore is refused if the original path is occupied or the quarantined payload no longer matches its evidence.' => 'Die Wiederherstellung wird verweigert, wenn der ursprüngliche Pfad belegt ist oder die Quarantäne-Nutzlast nicht mehr mit ihren Nachweisen übereinstimmt.',
        'Confirm your responsibility for backup and recovery before restoring the quarantined item.' => 'Bestätige deine Verantwortung für Sicherung und Wiederherstellung, bevor du das Quarantäne-Element wiederherstellst.',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-de_DE.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
