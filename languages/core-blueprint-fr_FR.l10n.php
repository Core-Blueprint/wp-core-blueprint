<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 *
 * The preserved release baseline remains byte-identical in languages/base/.
 * This wrapper layers the current feature strings on top without rewriting
 * the large generated catalog through a lossy transport boundary.
 */
$catalog = require __DIR__ . '/base/core-blueprint-fr_FR.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
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
        'Validate mapping.' => 'Valider le mappage.',
    ]
);

return $catalog;
