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
        'This account requires an interactive two-factor sign-in.' => 'Questo account richiede un accesso interattivo con autenticazione a due fattori.',
        'Two-factor authentication' => 'Autenticazione a due fattori',
        'The verification code was not accepted. Try again.' => 'Il codice di verifica non è stato accettato. Riprova.',
        'Add this account to your authenticator app, then enter the six-digit code to finish enrollment.' => 'Aggiungi questo account alla tua app di autenticazione, quindi inserisci il codice a sei cifre per completare la configurazione.',
        'Setup key' => 'Chiave di configurazione',
        'Open in an authenticator app' => 'Apri in un’app di autenticazione',
        'Enter your authenticator code or a recovery code.' => 'Inserisci il codice dell’app di autenticazione o un codice di recupero.',
        'Verification code' => 'Codice di verifica',
        'Verify' => 'Verifica',
        'Save your recovery codes' => 'Salva i codici di recupero',
        'Two-factor authentication is active. Save these recovery codes now. Each code can be used once.' => 'L’autenticazione a due fattori è attiva. Salva ora questi codici di recupero. Ogni codice può essere utilizzato una sola volta.',
        'This two-factor sign-in can no longer be used. Start a new sign-in attempt.' => 'Questo accesso con autenticazione a due fattori non può più essere utilizzato. Avvia un nuovo tentativo di accesso.',
        'Back to sign in' => 'Torna all’accesso',
        'Two-factor: enrollment completed' => 'Autenticazione a due fattori: configurazione completata',
        'Two-factor: recovery code used' => 'Autenticazione a due fattori: codice di recupero utilizzato',
        'Two-factor: authentication completed' => 'Autenticazione a due fattori: autenticazione completata',
        'Two-factor: Failsafe bypass used' => 'Autenticazione a due fattori: bypass Failsafe utilizzato',
        'Two-factor: imported authentication reset' => 'Autenticazione a due fattori: autenticazione importata reimpostata',
        'Two-factor: policy changed' => 'Autenticazione a due fattori: criterio modificato',
        'Invalid two-factor policy mode.' => 'Modalità del criterio di autenticazione a due fattori non valida.',
        'Two-factor policy changes require a trusted CB Operator.' => 'Le modifiche al criterio di autenticazione a due fattori richiedono un CB Operator attendibile.',
        'Two-factor enforcement cannot be enabled while Failsafe bypass is active.' => 'L’autenticazione a due fattori obbligatoria non può essere attivata mentre il bypass Failsafe è attivo.',
        'Two-factor enforcement requires the acting CB Operator to be enrolled in Base two-factor authentication.' => 'L’autenticazione a due fattori obbligatoria richiede che il CB Operator che esegue la modifica sia registrato all’autenticazione a due fattori di Base.',
        'Could not persist the two-factor policy.' => 'Impossibile salvare il criterio di autenticazione a due fattori.',
        'Two-factor policy changed during rollback and was not overwritten.' => 'Il criterio di autenticazione a due fattori è cambiato durante il rollback e non è stato sovrascritto.',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-it_IT.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
