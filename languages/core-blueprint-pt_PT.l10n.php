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
        'I understand that replacing this media file changes the attachment\'s stored files and that I am responsible for having a recent backup or other recovery option available.' => 'Compreendo que substituir este ficheiro multimédia altera os ficheiros armazenados do anexo e que sou responsável por ter uma cópia de segurança recente ou outra opção de recuperação disponível.',
        'Generated sizes, caches, themes, plugins and external references may affect the result.' => 'Os tamanhos gerados, as caches, os temas, os plugins e as referências externas podem afetar o resultado.',
        'Confirm your responsibility for backup and recovery before replacing the media file.' => 'Confirme a sua responsabilidade pela cópia de segurança e recuperação antes de substituir o ficheiro multimédia.',
        'I understand that importing this schema changes this site\'s Content Models configuration and that I am responsible for having a recent backup or other recovery option available.' => 'Compreendo que importar este esquema altera a configuração Content Models deste site e que sou responsável por ter uma cópia de segurança recente ou outra opção de recuperação disponível.',
        'Imported definitions can affect registered post types, taxonomies, Option Pages and fields. Existing definitions may be replaced when overwrite is selected.' => 'As definições importadas podem afetar tipos de conteúdo, taxonomias, Option Pages e campos registados. As definições existentes podem ser substituídas quando a opção de sobrescrever está selecionada.',
        'Confirm your responsibility for backup and recovery before importing the Content Models schema.' => 'Confirme a sua responsabilidade pela cópia de segurança e recuperação antes de importar o esquema Content Models.',
        'I understand that applying this plan transfers the selected WordPress schema registrations to Core Blueprint and that I am responsible for having a recent backup or other recovery option available.' => 'Compreendo que aplicar este plano transfere para o Core Blueprint os registos de esquema WordPress selecionados e que sou responsável por ter uma cópia de segurança recente ou outra opção de recuperação disponível.',
        'The original registrar must remain disabled after adoption. Plugins, themes and custom code may affect the resulting runtime.' => 'A origem de registo original deve permanecer desativada após a adoção. Plugins, temas e código personalizado podem afetar o funcionamento resultante.',
        'Confirm your responsibility for backup and recovery before applying the WordPress import plan.' => 'Confirme a sua responsabilidade pela cópia de segurança e recuperação antes de aplicar o plano de importação WordPress.',
        'I understand that preserving snippet IDs can replace existing managed snippet code and metadata and that I am responsible for having a recent backup or other recovery option available.' => 'Compreendo que preservar os IDs dos snippets pode substituir código e metadados de snippets geridos existentes e que sou responsável por ter uma cópia de segurança recente ou outra opção de recuperação disponível.',
        'Imported snippets remain disabled, but existing snippets with matching IDs can be overwritten.' => 'Os snippets importados permanecem desativados, mas os snippets existentes com IDs correspondentes podem ser sobrescritos.',
        'Confirm your responsibility for backup and recovery before restoring snippets with preserved IDs.' => 'Confirme a sua responsabilidade pela cópia de segurança e recuperação antes de restaurar snippets mantendo os respetivos IDs.',
        'I understand that this import will overwrite existing Notes and that I am responsible for having a recent backup or other recovery option available.' => 'Compreendo que esta importação irá sobrescrever Notes existentes e que sou responsável por ter uma cópia de segurança recente ou outra opção de recuperação disponível.',
        'Only Notes explicitly set to Overwrite existing are replaced.' => 'Apenas as Notes definidas explicitamente como Sobrescrever existente são substituídas.',
        'Confirm your responsibility for backup and recovery before overwriting existing Notes.' => 'Confirme a sua responsabilidade pela cópia de segurança e recuperação antes de sobrescrever Notes existentes.',
        'I understand that restoring this quarantined item changes the site\'s active filesystem and that I am responsible for having a recent backup or other recovery option available.' => 'Compreendo que restaurar este item em quarentena altera o sistema de ficheiros ativo do site e que sou responsável por ter uma cópia de segurança recente ou outra opção de recuperação disponível.',
        'The restore is refused if the original path is occupied or the quarantined payload no longer matches its evidence.' => 'O restauro é recusado se o caminho original estiver ocupado ou se o conteúdo em quarentena já não corresponder às respetivas evidências.',
        'Confirm your responsibility for backup and recovery before restoring the quarantined item.' => 'Confirme a sua responsabilidade pela cópia de segurança e recuperação antes de restaurar o item em quarentena.',
    ]
);

$profiles = require __DIR__ . '/profiles/core-blueprint-pt_PT.php';
if ( ! is_array( $profiles ) || ! isset( $profiles['messages'] ) || ! is_array( $profiles['messages'] ) ) {
    return [];
}
$catalog['messages'] = array_replace( $catalog['messages'], $profiles['messages'] );

return $catalog;
