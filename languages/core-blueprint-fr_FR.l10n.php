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
        'This account requires an interactive two-factor sign-in.' => 'Ce compte nécessite une connexion interactive avec authentification à deux facteurs.',
        'Two-factor authentication' => 'Authentification à deux facteurs',
        'The verification code was not accepted. Try again.' => 'Le code de vérification n’a pas été accepté. Réessayez.',
        'Add this account to your authenticator app, then enter the six-digit code to finish enrollment.' => 'Ajoutez ce compte à votre application d’authentification, puis saisissez le code à six chiffres pour terminer la configuration.',
        'Setup key' => 'Clé de configuration',
        'Open in an authenticator app' => 'Ouvrir dans une application d’authentification',
        'Enter your authenticator code or a recovery code.' => 'Saisissez votre code d’authentification ou un code de récupération.',
        'Verification code' => 'Code de vérification',
        'Verify' => 'Vérifier',
        'Save your recovery codes' => 'Enregistrez vos codes de récupération',
        'Two-factor authentication is active. Save these recovery codes now. Each code can be used once.' => 'L’authentification à deux facteurs est active. Enregistrez ces codes de récupération maintenant. Chaque code ne peut être utilisé qu’une seule fois.',
        'This two-factor sign-in can no longer be used. Start a new sign-in attempt.' => 'Cette connexion à deux facteurs ne peut plus être utilisée. Lancez une nouvelle tentative de connexion.',
        'Back to sign in' => 'Retour à la connexion',
        'Two-factor: enrollment completed' => 'Authentification à deux facteurs : configuration terminée',
        'Two-factor: recovery code used' => 'Authentification à deux facteurs : code de récupération utilisé',
        'Two-factor: authentication completed' => 'Authentification à deux facteurs : authentification terminée',
        'Two-factor: Failsafe bypass used' => 'Authentification à deux facteurs : contournement Failsafe utilisé',
        'Two-factor: imported authentication reset' => 'Authentification à deux facteurs : authentification importée réinitialisée',
        'Two-factor: policy changed' => 'Authentification à deux facteurs : politique modifiée',
        'Invalid two-factor policy mode.' => 'Mode de politique d’authentification à deux facteurs non valide.',
        'Two-factor policy changes require a trusted CB Operator.' => 'Les modifications de la politique d’authentification à deux facteurs nécessitent un CB Operator de confiance.',
        'Two-factor enforcement cannot be enabled while Failsafe bypass is active.' => 'L’authentification à deux facteurs obligatoire ne peut pas être activée tant que le contournement Failsafe est actif.',
        'Two-factor enforcement requires the acting CB Operator to be enrolled in Base two-factor authentication.' => 'L’authentification à deux facteurs obligatoire nécessite que le CB Operator agissant soit inscrit à l’authentification à deux facteurs de Base.',
        'Could not persist the two-factor policy.' => 'La politique d’authentification à deux facteurs n’a pas pu être enregistrée.',
        'Two-factor policy changed during rollback and was not overwritten.' => 'La politique d’authentification à deux facteurs a changé pendant le rollback et n’a pas été remplacée.',
        'Base two-factor enrollment is required for this user.' => 'L’inscription à l’authentification à deux facteurs de Base est requise pour cet utilisateur.',
        'Two-factor reset is available only through trusted server-side WP-CLI.' => 'La réinitialisation de l’authentification à deux facteurs est disponible uniquement via WP-CLI de confiance côté serveur.',
        'Base two-factor authentication reset failed.' => 'La réinitialisation de l’authentification à deux facteurs de Base a échoué.',
        'Base two-factor authentication reset completed.' => 'L’authentification à deux facteurs de Base a été réinitialisée.',
        'No Base two-factor authentication state required a reset.' => 'Aucun état d’authentification à deux facteurs de Base ne nécessitait de réinitialisation.',
        'Two-factor: authentication reset' => 'Authentification à deux facteurs : authentification réinitialisée',
        'An external two-factor provider already manages this account.' => 'Un fournisseur externe d’authentification à deux facteurs gère déjà ce compte.',
        'Base two-factor authentication is already active for this account.' => 'L’authentification à deux facteurs de Base est déjà active pour ce compte.',
        'Base two-factor authentication is not active for this account.' => 'L’authentification à deux facteurs de Base n’est pas active pour ce compte.',
        'Base two-factor authentication cannot be removed while enforcement is required for this account.' => 'L’authentification à deux facteurs de Base ne peut pas être supprimée tant qu’elle est obligatoire pour ce compte.',
        'Password confirmation failed. Two-factor authentication was not removed.' => 'La confirmation du mot de passe a échoué. L’authentification à deux facteurs n’a pas été supprimée.',
        'Two-factor verification failed. Two-factor authentication was not removed.' => 'La vérification à deux facteurs a échoué. L’authentification à deux facteurs n’a pas été supprimée.',
        'Base two-factor self-service is available only for your own privileged account.' => 'La gestion autonome de l’authentification à deux facteurs de Base est disponible uniquement pour votre propre compte privilégié.',
        'Two-factor: enrollment started' => 'Authentification à deux facteurs : configuration démarrée',
        'Two-factor: authentication removed' => 'Authentification à deux facteurs : authentification supprimée',
        'Password confirmation failed. Two-factor setup was not changed.' => 'La confirmation du mot de passe a échoué. La configuration de l’authentification à deux facteurs n’a pas été modifiée.',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-fr_FR.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
