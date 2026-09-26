<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 * Combines the base catalog with current feature translations.
 */
$catalog = require __DIR__ . '/base/core-blueprint-es_ES.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
        'Design loaded' => 'Diseño cargado',
        'Loading design…' => 'Cargando diseño…',
        'The selected design could not be loaded.' => 'No se pudo cargar el diseño seleccionado.',
        'The selected mail template is incomplete.' => 'La plantilla de correo electrónico seleccionada está incompleta.',
        'The SVG replacement could not be sanitized safely.' => 'No se pudo sanear de forma segura el archivo SVG de reemplazo.',
        'The sanitized SVG replacement no longer matches the expected file type.' => 'El archivo SVG de reemplazo saneado ya no coincide con el tipo de archivo esperado.',
        'Auto-match' => 'Correspondencia automática',
        'Choose a source file to begin mapping.' => 'Elige un archivo de origen para comenzar el mapeo.',
        'Choose source file' => 'Elegir archivo de origen',
        'Constant' => 'Constante',
        'Constant value' => 'Valor constante',
        'Data Exchange entity' => 'Entidad de Data Exchange',
        'Data Mapper' => 'Data Mapper',
        'Data Mapper details' => 'Detalles de Data Mapper',
        'Direct' => 'Directo',
        'Field mapping' => 'Mapeo de campos',
        'Ignore' => 'Ignorar',
        'Inspecting source file…' => 'Inspeccionando el archivo de origen…',
        'Map source fields to a target structure, validate the result, and review what will happen before data moves.' => 'Mapea los campos de origen a una estructura de destino, valida el resultado y revisa qué ocurrirá antes de mover los datos.',
        'Mapping' => 'Mapeo',
        'Mapping is complete.' => 'El mapeo está completo.',
        'Mapping needs attention.' => 'El mapeo requiere atención.',
        'No validated preview is available yet.' => 'Todavía no hay una vista previa validada disponible.',
        'Preview' => 'Vista previa',
        'Preview export' => 'Vista previa de exportación',
        'Provides versioned import and export of extension-owned data through the Core Blueprint Data Exchange Foundation.' => 'Proporciona importación y exportación versionadas de datos propiedad de extensiones mediante Core Blueprint Data Exchange Foundation.',
        'Required target fields are still unmapped.' => 'Los campos de destino obligatorios aún no están mapeados.',
        'Search fields' => 'Buscar campos',
        'Select a field mapping to inspect it.' => 'Selecciona un mapeo de campo para inspeccionarlo.',
        'Source file' => 'Archivo de origen',
        'Source file selected.' => 'Archivo de origen seleccionado.',
        'Target field' => 'Campo de destino',
        'Transform' => 'Transformación',
        'Validate mapping' => 'Validar mapeo',
        'This account requires an interactive two-factor sign-in.' => 'Esta cuenta requiere un inicio de sesión interactivo con autenticación de dos factores.',
        'Two-factor authentication' => 'Autenticación de dos factores',
        'The verification code was not accepted. Try again.' => 'No se aceptó el código de verificación. Inténtalo de nuevo.',
        'Add this account to your authenticator app, then enter the six-digit code to finish enrollment.' => 'Añade esta cuenta a tu aplicación de autenticación y, a continuación, introduce el código de seis dígitos para completar la configuración.',
        'Setup key' => 'Clave de configuración',
        'Open in an authenticator app' => 'Abrir en una aplicación de autenticación',
        'Enter your authenticator code or a recovery code.' => 'Introduce el código de tu aplicación de autenticación o un código de recuperación.',
        'Verification code' => 'Código de verificación',
        'Verify' => 'Verificar',
        'Save your recovery codes' => 'Guarda tus códigos de recuperación',
        'Two-factor authentication is active. Save these recovery codes now. Each code can be used once.' => 'La autenticación de dos factores está activa. Guarda estos códigos de recuperación ahora. Cada código se puede usar una sola vez.',
        'This two-factor sign-in can no longer be used. Start a new sign-in attempt.' => 'Este inicio de sesión con dos factores ya no se puede usar. Inicia un nuevo intento de acceso.',
        'Back to sign in' => 'Volver al inicio de sesión',
        'Two-factor: enrollment completed' => 'Autenticación de dos factores: configuración completada',
        'Two-factor: recovery code used' => 'Autenticación de dos factores: código de recuperación utilizado',
        'Two-factor: authentication completed' => 'Autenticación de dos factores: autenticación completada',
        'Two-factor: Failsafe bypass used' => 'Autenticación de dos factores: omisión de Failsafe utilizada',
        'Two-factor: imported authentication reset' => 'Autenticación de dos factores: autenticación importada restablecida',
        'Two-factor: policy changed' => 'Autenticación de dos factores: política modificada',
        'Invalid two-factor policy mode.' => 'Modo de política de autenticación de dos factores no válido.',
        'Two-factor policy changes require a trusted CB Operator.' => 'Los cambios en la política de autenticación de dos factores requieren un CB Operator de confianza.',
        'Two-factor enforcement cannot be enabled while Failsafe bypass is active.' => 'La autenticación de dos factores obligatoria no se puede activar mientras la omisión de Failsafe esté activa.',
        'Two-factor enforcement requires the acting CB Operator to be enrolled in Base two-factor authentication.' => 'La autenticación de dos factores obligatoria requiere que el CB Operator que realiza el cambio esté inscrito en la autenticación de dos factores de Base.',
        'Could not persist the two-factor policy.' => 'No se pudo guardar la política de autenticación de dos factores.',
        'Two-factor policy changed during rollback and was not overwritten.' => 'La política de autenticación de dos factores cambió durante la reversión y no se sobrescribió.',
        'Base two-factor enrollment is required for this user.' => 'Este usuario debe inscribirse en la autenticación de dos factores de Base.',
        'Two-factor reset is available only through trusted server-side WP-CLI.' => 'El restablecimiento de la autenticación de dos factores solo está disponible mediante WP-CLI de confianza en el servidor.',
        'Base two-factor authentication reset failed.' => 'No se pudo restablecer la autenticación de dos factores de Base.',
        'Base two-factor authentication reset completed.' => 'La autenticación de dos factores de Base se ha restablecido.',
        'No Base two-factor authentication state required a reset.' => 'No había ningún estado de autenticación de dos factores de Base que requiriera un restablecimiento.',
        'Two-factor: authentication reset' => 'Autenticación de dos factores: autenticación restablecida',
        'An external two-factor provider already manages this account.' => 'Un proveedor externo de autenticación de dos factores ya gestiona esta cuenta.',
        'Base two-factor authentication is already active for this account.' => 'La autenticación de dos factores de Base ya está activa para esta cuenta.',
        'Base two-factor authentication is not active for this account.' => 'La autenticación de dos factores de Base no está activa para esta cuenta.',
        'Base two-factor authentication cannot be removed while enforcement is required for this account.' => 'La autenticación de dos factores de Base no se puede eliminar mientras sea obligatoria para esta cuenta.',
        'Password confirmation failed. Two-factor authentication was not removed.' => 'La confirmación de la contraseña ha fallado. La autenticación de dos factores no se ha eliminado.',
        'Two-factor verification failed. Two-factor authentication was not removed.' => 'La verificación de dos factores ha fallado. La autenticación de dos factores no se ha eliminado.',
        'Base two-factor self-service is available only for your own privileged account.' => 'La autogestión de la autenticación de dos factores de Base solo está disponible para tu propia cuenta con privilegios.',
        'Two-factor: enrollment started' => 'Autenticación de dos factores: configuración iniciada',
        'Two-factor: authentication removed' => 'Autenticación de dos factores: autenticación eliminada',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-es_ES.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
