<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 * Combines the base catalog with current feature translations.
 */
$catalog = require __DIR__ . '/base/core-blueprint-fr_FR.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
        'Design loaded' => 'Modèle chargé',
        'Loading design…' => 'Chargement du modèle…',
        'The selected design could not be loaded.' => 'Le modèle sélectionné n’a pas pu être chargé.',
        'The selected mail template is incomplete.' => 'Le modèle d’e-mail sélectionné est incomplet.',
        'The SVG replacement could not be sanitized safely.' => 'Le fichier SVG de remplacement n’a pas pu être nettoyé de manière sûre.',
        'The sanitized SVG replacement no longer matches the expected file type.' => 'Le fichier SVG de remplacement nettoyé ne correspond plus au type de fichier attendu.',
        'Auto-match' => 'Correspondance automatique',
        'Choose a source file to begin mapping.' => 'Choisissez un fichier source pour commencer le mappage.',
        'Choose source file' => 'Choisir le fichier source',
        'Constant' => 'Constante',
        'Constant value' => 'Valeur constante',
        'Data Exchange entity' => 'Entité Data Exchange',
        'Data Mapper' => 'Data Mapper',
        'Data Mapper details' => 'Détails du Data Mapper',
        'Direct' => 'Direct',
        'Field mapping' => 'Mappage de champs',
        'Ignore' => 'Ignorer',
        'Inspecting source file…' => 'Analyse du fichier source…',
        'Map source fields to a target structure, validate the result, and review what will happen before data moves.' => 'Mappez les champs source vers une structure cible, validez le résultat et vérifiez ce qui se passera avant le transfert des données.',
        'Mapping' => 'Mappage',
        'Mapping is complete.' => 'Le mappage est terminé.',
        'Mapping needs attention.' => 'Le mappage nécessite votre attention.',
        'No validated preview is available yet.' => 'Aucun aperçu validé n’est encore disponible.',
        'Preview' => 'Aperçu',
        'Preview export' => 'Aperçu de l’export',
        'Provides versioned import and export of extension-owned data through the Core Blueprint Data Exchange Foundation.' => 'Fournit l’import et l’export versionnés des données appartenant aux extensions via la Core Blueprint Data Exchange Foundation.',
        'Required target fields are still unmapped.' => 'Les champs cibles obligatoires ne sont pas encore mappés.',
        'Search fields' => 'Rechercher des champs',
        'Select a field mapping to inspect it.' => 'Sélectionnez un mappage de champ pour l’examiner.',
        'Source file' => 'Fichier source',
        'Source file selected.' => 'Fichier source sélectionné.',
        'Target field' => 'Champ cible',
        'Transform' => 'Transformation',
        'Validate mapping' => 'Valider le mappage',
        'I understand that replacing this media file changes the attachment\'s stored files and that I am responsible for having a recent backup or other recovery option available.' => 'Je comprends que le remplacement de ce fichier média modifie les fichiers stockés de la pièce jointe et qu’il m’incombe de disposer d’une sauvegarde récente ou d’une autre solution de récupération.',
        'Generated sizes, caches, themes, plugins and external references may affect the result.' => 'Les tailles générées, les caches, les thèmes, les extensions et les références externes peuvent affecter le résultat.',
        'Confirm your responsibility for backup and recovery before replacing the media file.' => 'Confirmez votre responsabilité en matière de sauvegarde et de récupération avant de remplacer le fichier média.',
        'I understand that importing this schema changes this site\'s Content Models configuration and that I am responsible for having a recent backup or other recovery option available.' => 'Je comprends que l’import de ce schéma modifie la configuration Content Models de ce site et qu’il m’incombe de disposer d’une sauvegarde récente ou d’une autre solution de récupération.',
        'Imported definitions can affect registered post types, taxonomies, Option Pages and fields. Existing definitions may be replaced when overwrite is selected.' => 'Les définitions importées peuvent affecter les types de publication, les taxonomies, les Option Pages et les champs enregistrés. Les définitions existantes peuvent être remplacées lorsque l’écrasement est sélectionné.',
        'Confirm your responsibility for backup and recovery before importing the Content Models schema.' => 'Confirmez votre responsabilité en matière de sauvegarde et de récupération avant d’importer le schéma Content Models.',
        'I understand that applying this plan transfers the selected WordPress schema registrations to Core Blueprint and that I am responsible for having a recent backup or other recovery option available.' => 'Je comprends que l’application de ce plan transfère les enregistrements de schéma WordPress sélectionnés vers Core Blueprint et qu’il m’incombe de disposer d’une sauvegarde récente ou d’une autre solution de récupération.',
        'The original registrar must remain disabled after adoption. Plugins, themes and custom code may affect the resulting runtime.' => 'La source d’enregistrement d’origine doit rester désactivée après l’adoption. Les extensions, les thèmes et le code personnalisé peuvent affecter le fonctionnement obtenu.',
        'Confirm your responsibility for backup and recovery before applying the WordPress import plan.' => 'Confirmez votre responsabilité en matière de sauvegarde et de récupération avant d’appliquer le plan d’import WordPress.',
        'I understand that preserving snippet IDs can replace existing managed snippet code and metadata and that I am responsible for having a recent backup or other recovery option available.' => 'Je comprends que la conservation des identifiants de snippets peut remplacer le code et les métadonnées de snippets gérés existants et qu’il m’incombe de disposer d’une sauvegarde récente ou d’une autre solution de récupération.',
        'Imported snippets remain disabled, but existing snippets with matching IDs can be overwritten.' => 'Les snippets importés restent désactivés, mais les snippets existants ayant des identifiants correspondants peuvent être écrasés.',
        'Confirm your responsibility for backup and recovery before restoring snippets with preserved IDs.' => 'Confirmez votre responsabilité en matière de sauvegarde et de récupération avant de restaurer des snippets avec leurs identifiants conservés.',
        'I understand that this import will overwrite existing Notes and that I am responsible for having a recent backup or other recovery option available.' => 'Je comprends que cet import écrasera des Notes existantes et qu’il m’incombe de disposer d’une sauvegarde récente ou d’une autre solution de récupération.',
        'Only Notes explicitly set to Overwrite existing are replaced.' => 'Seules les Notes explicitement définies sur Écraser l’existant sont remplacées.',
        'Confirm your responsibility for backup and recovery before overwriting existing Notes.' => 'Confirmez votre responsabilité en matière de sauvegarde et de récupération avant d’écraser des Notes existantes.',
        'I understand that restoring this quarantined item changes the site\'s active filesystem and that I am responsible for having a recent backup or other recovery option available.' => 'Je comprends que la restauration de cet élément mis en quarantaine modifie le système de fichiers actif du site et qu’il m’incombe de disposer d’une sauvegarde récente ou d’une autre solution de récupération.',
        'The restore is refused if the original path is occupied or the quarantined payload no longer matches its evidence.' => 'La restauration est refusée si le chemin d’origine est occupé ou si le contenu mis en quarantaine ne correspond plus à ses preuves.',
        'Confirm your responsibility for backup and recovery before restoring the quarantined item.' => 'Confirmez votre responsabilité en matière de sauvegarde et de récupération avant de restaurer l’élément mis en quarantaine.',
        'Media Replace: backup and recovery responsibility acknowledged' => 'Media Replace : responsabilité de sauvegarde et de récupération confirmée',
        'Content Models: schema import backup and recovery responsibility acknowledged' => 'Content Models : responsabilité de sauvegarde et de récupération confirmée pour l’import du schéma',
        'Content Models: native import backup and recovery responsibility acknowledged' => 'Content Models : responsabilité de sauvegarde et de récupération confirmée pour l’import natif',
        'Snippets: restore backup and recovery responsibility acknowledged' => 'Snippets : responsabilité de sauvegarde et de récupération confirmée pour la restauration',
        'Notes: import overwrite backup and recovery responsibility acknowledged' => 'Notes : responsabilité de sauvegarde et de récupération confirmée pour l’import avec écrasement',
        'Core Scanner: quarantine restore backup and recovery responsibility acknowledged' => 'Core Scanner : responsabilité de sauvegarde et de récupération confirmée pour la restauration de quarantaine',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-fr_FR.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
