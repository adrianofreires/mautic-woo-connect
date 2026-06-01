jQuery(document).ready(function ($) {
    // 1. Campo de Status de Pedido (pesquisável)
    if ($.fn.selectWoo) {
        $('select[name="mwc_valid_order_statuses[]"]').selectWoo({
            placeholder: "Clique para selecionar os status...",
            allowClear: true
        });
    }

    // 2. Confirmação no Bulk Sync
    $('input[value="mwc_run_bulk_sync"]').closest('form').on('submit', function (e) {
        var confirmacao = confirm('Atenção: Você está prestes a sincronizar todo o histórico da loja para o Mautic. Este processo rodará em segundo plano. Deseja continuar?');
        if (!confirmacao) {
            e.preventDefault();
        }
    });

    // --- Mapeamento de campos (repeater) ---
    function updateMauticSelects() {
        var selectedFields = [];
        $('.mwc-mautic-select').each(function () {
            var val = $(this).val();
            if (val !== '') { selectedFields.push(val); }
        });
        $('.mwc-mautic-select').each(function () {
            var currentVal = $(this).val();
            $(this).find('option').each(function () {
                var optionVal = $(this).val();
                if (optionVal !== '' && optionVal !== currentVal && selectedFields.includes(optionVal)) {
                    $(this).prop('disabled', true);
                } else {
                    $(this).prop('disabled', false);
                }
            });
        });
    }

    $('#mwc-add-row').on('click', function (e) {
        e.preventDefault();
        var firstRow = $('.mwc-mapping-row').first().clone();
        firstRow.find('select').val('');
        $('#mwc-mapping-body').append(firstRow);
        updateMauticSelects();
    });

    $(document).on('click', '.mwc-remove-row', function (e) {
        e.preventDefault();
        if ($('.mwc-mapping-row').length > 1) {
            $(this).closest('tr').remove();
            updateMauticSelects();
        } else {
            alert('A última linha não pode ser removida. Apenas deixe em branco se não quiser mapear.');
        }
    });

    $(document).on('change', '.mwc-mautic-select', updateMauticSelects);
    updateMauticSelects();

    // --- Toggle dos campos de Origem ---
    function toggleOriginFields() {
        if ($('#mwc_enable_auto_origin').is(':checked')) {
            $('.mwc-origin-dependent-field').fadeIn('fast');
            $('#mwc_auto_origin_label').text('Sim (Detectar do Domínio)');
        } else {
            $('.mwc-origin-dependent-field').hide();
            $('#mwc_auto_origin_label').text('Não (Usar Texto Fixo)');
        }
    }

    $('#mwc_enable_auto_origin').on('change', toggleOriginFields);
    toggleOriginFields();
});