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
        'This account requires an interactive two-factor sign-in.' => 'Für dieses Konto ist eine interaktive Anmeldung mit Zwei-Faktor-Authentifizierung erforderlich.',
        'Two-factor authentication' => 'Zwei-Faktor-Authentifizierung',
        'The verification code was not accepted. Try again.' => 'Der Bestätigungscode wurde nicht akzeptiert. Versuchen Sie es erneut.',
        'Add this account to your authenticator app, then enter the six-digit code to finish enrollment.' => 'Fügen Sie dieses Konto Ihrer Authenticator-App hinzu und geben Sie anschließend den sechsstelligen Code ein, um die Einrichtung abzuschließen.',
        'Setup key' => 'Einrichtungsschlüssel',
        'Open in an authenticator app' => 'In einer Authenticator-App öffnen',
        'Enter your authenticator code or a recovery code.' => 'Geben Sie Ihren Authenticator-Code oder einen Wiederherstellungscode ein.',
        'Verification code' => 'Bestätigungscode',
        'Verify' => 'Bestätigen',
        'Save your recovery codes' => 'Wiederherstellungscodes speichern',
        'Two-factor authentication is active. Save these recovery codes now. Each code can be used once.' => 'Die Zwei-Faktor-Authentifizierung ist aktiv. Speichern Sie diese Wiederherstellungscodes jetzt. Jeder Code kann einmal verwendet werden.',
        'This two-factor sign-in can no longer be used. Start a new sign-in attempt.' => 'Diese Zwei-Faktor-Anmeldung kann nicht mehr verwendet werden. Starten Sie einen neuen Anmeldeversuch.',
        'Back to sign in' => 'Zurück zur Anmeldung',
        'Two-factor: enrollment completed' => 'Zwei-Faktor-Authentifizierung: Einrichtung abgeschlossen',
        'Two-factor: recovery code used' => 'Zwei-Faktor-Authentifizierung: Wiederherstellungscode verwendet',
        'Two-factor: authentication completed' => 'Zwei-Faktor-Authentifizierung: Authentifizierung abgeschlossen',
        'Two-factor: Failsafe bypass used' => 'Zwei-Faktor-Authentifizierung: Failsafe-Umgehung verwendet',
        'Two-factor: imported authentication reset' => 'Zwei-Faktor-Authentifizierung: importierte Authentifizierung zurückgesetzt',
        'Two-factor: policy changed' => 'Zwei-Faktor-Authentifizierung: Richtlinie geändert',
        'Invalid two-factor policy mode.' => 'Ungültiger Modus für die Zwei-Faktor-Richtlinie.',
        'Two-factor policy changes require a trusted CB Operator.' => 'Änderungen an der Zwei-Faktor-Richtlinie erfordern einen vertrauenswürdigen CB Operator.',
        'Two-factor enforcement cannot be enabled while Failsafe bypass is active.' => 'Die verpflichtende Zwei-Faktor-Authentifizierung kann nicht aktiviert werden, solange der Failsafe-Bypass aktiv ist.',
        'Two-factor enforcement requires the acting CB Operator to be enrolled in Base two-factor authentication.' => 'Für die verpflichtende Zwei-Faktor-Authentifizierung muss der ausführende CB Operator für die Base-Zwei-Faktor-Authentifizierung registriert sein.',
        'Could not persist the two-factor policy.' => 'Die Zwei-Faktor-Richtlinie konnte nicht gespeichert werden.',
        'Two-factor policy changed during rollback and was not overwritten.' => 'Die Zwei-Faktor-Richtlinie wurde während des Rollbacks geändert und nicht überschrieben.',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-de_DE.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
