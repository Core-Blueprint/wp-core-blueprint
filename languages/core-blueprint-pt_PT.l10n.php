<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 * Combines the base catalog with current feature translations.
 */
$catalog = require __DIR__ . '/base/core-blueprint-pt_PT.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
        'Design loaded' => 'Design carregado',
        'Loading design…' => 'A carregar o design…',
        'The selected design could not be loaded.' => 'Não foi possível carregar o design selecionado.',
        'The selected mail template is incomplete.' => 'O modelo de e-mail selecionado está incompleto.',
        'The SVG replacement could not be sanitized safely.' => 'Não foi possível limpar com segurança o ficheiro SVG de substituição.',
        'The sanitized SVG replacement no longer matches the expected file type.' => 'O ficheiro SVG de substituição limpo já não corresponde ao tipo de ficheiro esperado.',
        'Auto-match' => 'Correspondência automática',
        'Choose a source file to begin mapping.' => 'Escolha um ficheiro de origem para começar o mapeamento.',
        'Choose source file' => 'Escolher ficheiro de origem',
        'Constant' => 'Constante',
        'Constant value' => 'Valor constante',
        'Data Exchange entity' => 'Entidade do Data Exchange',
        'Data Mapper' => 'Data Mapper',
        'Data Mapper details' => 'Detalhes do Data Mapper',
        'Direct' => 'Direto',
        'Field mapping' => 'Mapeamento de campos',
        'Ignore' => 'Ignorar',
        'Inspecting source file…' => 'A analisar o ficheiro de origem…',
        'Map source fields to a target structure, validate the result, and review what will happen before data moves.' => 'Mapeie os campos de origem para uma estrutura de destino, valide o resultado e reveja o que irá acontecer antes de os dados serem movidos.',
        'Mapping' => 'Mapeamento',
        'Mapping is complete.' => 'O mapeamento está concluído.',
        'Mapping needs attention.' => 'O mapeamento requer atenção.',
        'No validated preview is available yet.' => 'Ainda não está disponível uma pré-visualização validada.',
        'Preview' => 'Pré-visualização',
        'Preview export' => 'Pré-visualização da exportação',
        'Provides versioned import and export of extension-owned data through the Core Blueprint Data Exchange Foundation.' => 'Fornece importação e exportação com controlo de versões de dados pertencentes às extensões através da Core Blueprint Data Exchange Foundation.',
        'Required target fields are still unmapped.' => 'Os campos de destino obrigatórios ainda não estão mapeados.',
        'Search fields' => 'Pesquisar campos',
        'Select a field mapping to inspect it.' => 'Selecione um mapeamento de campo para o inspecionar.',
        'Source file' => 'Ficheiro de origem',
        'Source file selected.' => 'Ficheiro de origem selecionado.',
        'Target field' => 'Campo de destino',
        'Transform' => 'Transformação',
        'Validate mapping' => 'Validar mapeamento',
        'This account requires an interactive two-factor sign-in.' => 'Esta conta requer um início de sessão interativo com autenticação de dois fatores.',
        'Two-factor authentication' => 'Autenticação de dois fatores',
        'The verification code was not accepted. Try again.' => 'O código de verificação não foi aceite. Tente novamente.',
        'Add this account to your authenticator app, then enter the six-digit code to finish enrollment.' => 'Adicione esta conta à sua aplicação de autenticação e, em seguida, introduza o código de seis dígitos para concluir a configuração.',
        'Setup key' => 'Chave de configuração',
        'Open in an authenticator app' => 'Abrir numa aplicação de autenticação',
        'Enter your authenticator code or a recovery code.' => 'Introduza o código da sua aplicação de autenticação ou um código de recuperação.',
        'Verification code' => 'Código de verificação',
        'Verify' => 'Verificar',
        'Save your recovery codes' => 'Guarde os seus códigos de recuperação',
        'Two-factor authentication is active. Save these recovery codes now. Each code can be used once.' => 'A autenticação de dois fatores está ativa. Guarde estes códigos de recuperação agora. Cada código pode ser utilizado uma única vez.',
        'This two-factor sign-in can no longer be used. Start a new sign-in attempt.' => 'Este início de sessão com autenticação de dois fatores já não pode ser utilizado. Inicie uma nova tentativa de início de sessão.',
        'Back to sign in' => 'Voltar ao início de sessão',
        'Two-factor: enrollment completed' => 'Autenticação de dois fatores: configuração concluída',
        'Two-factor: recovery code used' => 'Autenticação de dois fatores: código de recuperação utilizado',
        'Two-factor: authentication completed' => 'Autenticação de dois fatores: autenticação concluída',
        'Two-factor: Failsafe bypass used' => 'Autenticação de dois fatores: bypass Failsafe utilizado',
        'Two-factor: imported authentication reset' => 'Autenticação de dois fatores: autenticação importada reposta',
        'Two-factor: policy changed' => 'Autenticação de dois fatores: política alterada',
        'Invalid two-factor policy mode.' => 'Modo de política de autenticação de dois fatores inválido.',
        'Two-factor policy changes require a trusted CB Operator.' => 'As alterações à política de autenticação de dois fatores requerem um CB Operator de confiança.',
        'Two-factor enforcement cannot be enabled while Failsafe bypass is active.' => 'A autenticação de dois fatores obrigatória não pode ser ativada enquanto o bypass Failsafe estiver ativo.',
        'Two-factor enforcement requires the acting CB Operator to be enrolled in Base two-factor authentication.' => 'A autenticação de dois fatores obrigatória requer que o CB Operator que efetua a alteração esteja inscrito na autenticação de dois fatores do Base.',
        'Could not persist the two-factor policy.' => 'Não foi possível guardar a política de autenticação de dois fatores.',
        'Two-factor policy changed during rollback and was not overwritten.' => 'A política de autenticação de dois fatores foi alterada durante o rollback e não foi substituída.',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-pt_PT.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
