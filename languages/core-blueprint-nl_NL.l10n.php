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
        'I understand that replacing this media file changes the attachment\'s stored files and that I am responsible for having a recent backup or other recovery option available.' => 'Ik begrijp dat het vervangen van dit mediabestand de opgeslagen bestanden van de bijlage wijzigt en dat ik verantwoordelijk ben voor een recente back-up of andere herstelmogelijkheid.',
        'Generated sizes, caches, themes, plugins and external references may affect the result.' => 'Gegenereerde formaten, caches, thema\'s, plugins en externe verwijzingen kunnen het resultaat beïnvloeden.',
        'Confirm your responsibility for backup and recovery before replacing the media file.' => 'Bevestig je verantwoordelijkheid voor back-up en herstel voordat je het mediabestand vervangt.',
        'I understand that importing this schema changes this site\'s Content Models configuration and that I am responsible for having a recent backup or other recovery option available.' => 'Ik begrijp dat het importeren van dit schema de Content Models-configuratie van deze site wijzigt en dat ik verantwoordelijk ben voor een recente back-up of andere herstelmogelijkheid.',
        'Imported definitions can affect registered post types, taxonomies, Option Pages and fields. Existing definitions may be replaced when overwrite is selected.' => 'Geïmporteerde definities kunnen geregistreerde berichttypen, taxonomieën, Option Pages en velden beïnvloeden. Bestaande definities kunnen worden vervangen wanneer overschrijven is geselecteerd.',
        'Confirm your responsibility for backup and recovery before importing the Content Models schema.' => 'Bevestig je verantwoordelijkheid voor back-up en herstel voordat je het Content Models-schema importeert.',
        'I understand that applying this plan transfers the selected WordPress schema registrations to Core Blueprint and that I am responsible for having a recent backup or other recovery option available.' => 'Ik begrijp dat het toepassen van dit plan de geselecteerde WordPress-schemaregistraties overdraagt aan Core Blueprint en dat ik verantwoordelijk ben voor een recente back-up of andere herstelmogelijkheid.',
        'The original registrar must remain disabled after adoption. Plugins, themes and custom code may affect the resulting runtime.' => 'De oorspronkelijke registratiebron moet na de overname uitgeschakeld blijven. Plugins, thema\'s en aangepaste code kunnen de uiteindelijke werking beïnvloeden.',
        'Confirm your responsibility for backup and recovery before applying the WordPress import plan.' => 'Bevestig je verantwoordelijkheid voor back-up en herstel voordat je het WordPress-importplan toepast.',
        'I understand that preserving snippet IDs can replace existing managed snippet code and metadata and that I am responsible for having a recent backup or other recovery option available.' => 'Ik begrijp dat het behouden van snippet-ID\'s bestaande beheerde snippetcode en metadata kan vervangen en dat ik verantwoordelijk ben voor een recente back-up of andere herstelmogelijkheid.',
        'Imported snippets remain disabled, but existing snippets with matching IDs can be overwritten.' => 'Geïmporteerde snippets blijven uitgeschakeld, maar bestaande snippets met overeenkomende ID\'s kunnen worden overschreven.',
        'Confirm your responsibility for backup and recovery before restoring snippets with preserved IDs.' => 'Bevestig je verantwoordelijkheid voor back-up en herstel voordat je snippets met behouden ID\'s herstelt.',
        'I understand that this import will overwrite existing Notes and that I am responsible for having a recent backup or other recovery option available.' => 'Ik begrijp dat deze import bestaande Notes overschrijft en dat ik verantwoordelijk ben voor een recente back-up of andere herstelmogelijkheid.',
        'Only Notes explicitly set to Overwrite existing are replaced.' => 'Alleen Notes die expliciet op Bestaande overschrijven zijn gezet, worden vervangen.',
        'Confirm your responsibility for backup and recovery before overwriting existing Notes.' => 'Bevestig je verantwoordelijkheid voor back-up en herstel voordat je bestaande Notes overschrijft.',
        'I understand that restoring this quarantined item changes the site\'s active filesystem and that I am responsible for having a recent backup or other recovery option available.' => 'Ik begrijp dat het herstellen van dit in quarantaine geplaatste item het actieve bestandssysteem van de site wijzigt en dat ik verantwoordelijk ben voor een recente back-up of andere herstelmogelijkheid.',
        'The restore is refused if the original path is occupied or the quarantined payload no longer matches its evidence.' => 'Het herstel wordt geweigerd als het oorspronkelijke pad bezet is of de quarantainepayload niet meer overeenkomt met het bewijs.',
        'Confirm your responsibility for backup and recovery before restoring the quarantined item.' => 'Bevestig je verantwoordelijkheid voor back-up en herstel voordat je het in quarantaine geplaatste item herstelt.',
        'Media Replace: backup and recovery responsibility acknowledged' => 'Media Replace: verantwoordelijkheid voor back-up en herstel bevestigd',
        'Content Models: schema import backup and recovery responsibility acknowledged' => 'Content Models: verantwoordelijkheid voor back-up en herstel bij schema-import bevestigd',
        'Content Models: native import backup and recovery responsibility acknowledged' => 'Content Models: verantwoordelijkheid voor back-up en herstel bij native import bevestigd',
        'Snippets: restore backup and recovery responsibility acknowledged' => 'Snippets: verantwoordelijkheid voor back-up en herstel bij restore bevestigd',
        'Notes: import overwrite backup and recovery responsibility acknowledged' => 'Notes: verantwoordelijkheid voor back-up en herstel bij overschrijvende import bevestigd',
        'Core Scanner: quarantine restore backup and recovery responsibility acknowledged' => 'Core Scanner: verantwoordelijkheid voor back-up en herstel bij quarantaineherstel bevestigd',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-nl_NL.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
