<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 * Combines the base catalog with current feature translations.
 */
$catalog = require __DIR__ . '/base/core-blueprint-it_IT.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
        'Design loaded' => 'Design caricato',
        'Loading design…' => 'Caricamento del design…',
        'The selected design could not be loaded.' => 'Impossibile caricare il design selezionato.',
        'The selected mail template is incomplete.' => 'Il modello di e-mail selezionato è incompleto.',
        'The SVG replacement could not be sanitized safely.' => 'Impossibile ripulire in modo sicuro il file SVG sostitutivo.',
        'The sanitized SVG replacement no longer matches the expected file type.' => 'Il file SVG sostitutivo ripulito non corrisponde più al tipo di file previsto.',
        'Auto-match' => 'Abbinamento automatico',
        'Choose a source file to begin mapping.' => 'Scegli un file sorgente per iniziare la mappatura.',
        'Choose source file' => 'Scegli file sorgente',
        'Constant' => 'Costante',
        'Constant value' => 'Valore costante',
        'Data Exchange entity' => 'Entità Data Exchange',
        'Data Mapper' => 'Data Mapper',
        'Data Mapper details' => 'Dettagli Data Mapper',
        'Direct' => 'Diretto',
        'Field mapping' => 'Mappatura dei campi',
        'Ignore' => 'Ignora',
        'Inspecting source file…' => 'Analisi del file sorgente…',
        'Map source fields to a target structure, validate the result, and review what will happen before data moves.' => 'Mappa i campi sorgente su una struttura di destinazione, convalida il risultato e verifica cosa accadrà prima dello spostamento dei dati.',
        'Mapping' => 'Mappatura',
        'Mapping is complete.' => 'La mappatura è completa.',
        'Mapping needs attention.' => 'La mappatura richiede attenzione.',
        'No validated preview is available yet.' => 'Non è ancora disponibile un’anteprima convalidata.',
        'Preview' => 'Anteprima',
        'Preview export' => 'Anteprima esportazione',
        'Provides versioned import and export of extension-owned data through the Core Blueprint Data Exchange Foundation.' => 'Fornisce importazione ed esportazione versionate dei dati di proprietà delle estensioni tramite Core Blueprint Data Exchange Foundation.',
        'Required target fields are still unmapped.' => 'I campi di destinazione obbligatori non sono ancora mappati.',
        'Search fields' => 'Cerca campi',
        'Select a field mapping to inspect it.' => 'Seleziona una mappatura di campo per esaminarla.',
        'Source file' => 'File sorgente',
        'Source file selected.' => 'File sorgente selezionato.',
        'Target field' => 'Campo di destinazione',
        'Transform' => 'Trasformazione',
        'Validate mapping' => 'Convalida mappatura',
    ]
);

return $catalog;
