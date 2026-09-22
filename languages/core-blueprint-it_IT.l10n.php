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
        'I understand that replacing this media file changes the attachment\'s stored files and that I am responsible for having a recent backup or other recovery option available.' => 'Comprendo che la sostituzione di questo file multimediale modifica i file memorizzati dell’allegato e che sono responsabile di disporre di un backup recente o di un’altra opzione di ripristino.',
        'Generated sizes, caches, themes, plugins and external references may affect the result.' => 'Le dimensioni generate, le cache, i temi, i plugin e i riferimenti esterni possono influire sul risultato.',
        'Confirm your responsibility for backup and recovery before replacing the media file.' => 'Conferma la tua responsabilità per backup e ripristino prima di sostituire il file multimediale.',
        'I understand that importing this schema changes this site\'s Content Models configuration and that I am responsible for having a recent backup or other recovery option available.' => 'Comprendo che l’importazione di questo schema modifica la configurazione Content Models di questo sito e che sono responsabile di disporre di un backup recente o di un’altra opzione di ripristino.',
        'Imported definitions can affect registered post types, taxonomies, Option Pages and fields. Existing definitions may be replaced when overwrite is selected.' => 'Le definizioni importate possono influire su tipi di contenuto, tassonomie, Option Pages e campi registrati. Le definizioni esistenti possono essere sostituite quando è selezionata la sovrascrittura.',
        'Confirm your responsibility for backup and recovery before importing the Content Models schema.' => 'Conferma la tua responsabilità per backup e ripristino prima di importare lo schema Content Models.',
        'I understand that applying this plan transfers the selected WordPress schema registrations to Core Blueprint and that I am responsible for having a recent backup or other recovery option available.' => 'Comprendo che l’applicazione di questo piano trasferisce a Core Blueprint le registrazioni dello schema WordPress selezionate e che sono responsabile di disporre di un backup recente o di un’altra opzione di ripristino.',
        'The original registrar must remain disabled after adoption. Plugins, themes and custom code may affect the resulting runtime.' => 'La fonte di registrazione originale deve rimanere disabilitata dopo l’adozione. Plugin, temi e codice personalizzato possono influire sul funzionamento risultante.',
        'Confirm your responsibility for backup and recovery before applying the WordPress import plan.' => 'Conferma la tua responsabilità per backup e ripristino prima di applicare il piano di importazione WordPress.',
        'I understand that preserving snippet IDs can replace existing managed snippet code and metadata and that I am responsible for having a recent backup or other recovery option available.' => 'Comprendo che conservare gli ID degli snippet può sostituire il codice e i metadati degli snippet gestiti esistenti e che sono responsabile di disporre di un backup recente o di un’altra opzione di ripristino.',
        'Imported snippets remain disabled, but existing snippets with matching IDs can be overwritten.' => 'Gli snippet importati rimangono disabilitati, ma gli snippet esistenti con ID corrispondenti possono essere sovrascritti.',
        'Confirm your responsibility for backup and recovery before restoring snippets with preserved IDs.' => 'Conferma la tua responsabilità per backup e ripristino prima di ripristinare snippet mantenendo i loro ID.',
        'I understand that this import will overwrite existing Notes and that I am responsible for having a recent backup or other recovery option available.' => 'Comprendo che questa importazione sovrascriverà Notes esistenti e che sono responsabile di disporre di un backup recente o di un’altra opzione di ripristino.',
        'Only Notes explicitly set to Overwrite existing are replaced.' => 'Vengono sostituite solo le Notes impostate esplicitamente su Sovrascrivi esistente.',
        'Confirm your responsibility for backup and recovery before overwriting existing Notes.' => 'Conferma la tua responsabilità per backup e ripristino prima di sovrascrivere Notes esistenti.',
        'I understand that restoring this quarantined item changes the site\'s active filesystem and that I am responsible for having a recent backup or other recovery option available.' => 'Comprendo che il ripristino di questo elemento in quarantena modifica il file system attivo del sito e che sono responsabile di disporre di un backup recente o di un’altra opzione di ripristino.',
        'The restore is refused if the original path is occupied or the quarantined payload no longer matches its evidence.' => 'Il ripristino viene rifiutato se il percorso originale è occupato o se il payload in quarantena non corrisponde più alle relative evidenze.',
        'Confirm your responsibility for backup and recovery before restoring the quarantined item.' => 'Conferma la tua responsabilità per backup e ripristino prima di ripristinare l’elemento in quarantena.',
        'Media Replace: backup and recovery responsibility acknowledged' => 'Media Replace: responsabilità per backup e ripristino confermata',
        'Content Models: schema import backup and recovery responsibility acknowledged' => 'Content Models: responsabilità per backup e ripristino confermata per l’importazione dello schema',
        'Content Models: native import backup and recovery responsibility acknowledged' => 'Content Models: responsabilità per backup e ripristino confermata per l’importazione nativa',
        'Snippets: restore backup and recovery responsibility acknowledged' => 'Snippets: responsabilità per backup e ripristino confermata per il ripristino',
        'Notes: import overwrite backup and recovery responsibility acknowledged' => 'Notes: responsabilità per backup e ripristino confermata per l’importazione con sovrascrittura',
        'Core Scanner: quarantine restore backup and recovery responsibility acknowledged' => 'Core Scanner: responsabilità per backup e ripristino confermata per il ripristino dalla quarantena',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-it_IT.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
