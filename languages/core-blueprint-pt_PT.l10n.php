<?php
declare(strict_types=1);

/**
 * Composed Core Blueprint PHP translation catalog.
 *
 * The preserved release baseline remains byte-identical in languages/base/.
 * This wrapper layers the current feature strings on top without rewriting
 * the large generated catalog through a lossy transport boundary.
 */
$catalog = require __DIR__ . '/base/core-blueprint-pt_PT.php';

if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
    return [];
}

$catalog['messages'] = array_replace(
    $catalog['messages'],
    [
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
        'Validate mapping.' => 'Validar mapeamento.',
    ]
);

return $catalog;
