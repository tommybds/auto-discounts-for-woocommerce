/**
 * Script d'administration pour WooCommerce Auto Discounts
 *
 * @package WooCommerce_Auto_Discounts
 */
jQuery(document).ready(function($) {
    // Initialiser les select2 pour les catégories si disponibles
    if ($.fn.select2) {
        $('.category-select').select2();
    }
    
    // Variable pour suivre l'indice des règles
    var ruleIndex = $('#rules-container tr').length;
    
    // Gestion du bouton "Ajouter une règle"
    $('#add-rule').on('click', function() {
        var newRow = `
            <tr>
                <td><input type="number" name="wcad_discount_rules[${ruleIndex}][priority]" value="${ruleIndex + 1}"></td>
                <td><input type="number" name="wcad_discount_rules[${ruleIndex}][min_age]" value="30"></td>
                <td><input type="number" name="wcad_discount_rules[${ruleIndex}][discount]" value="10" step="0.01"></td>
                <td><input type="checkbox" name="wcad_discount_rules[${ruleIndex}][active]" value="1" checked></td>
                <td><input type="checkbox" name="wcad_discount_rules[${ruleIndex}][respect_manual]" value="1"></td>
                <td><button type="button" class="button remove-rule">${wcadData.i18n.remove_rule || 'Supprimer'}</button></td>
            </tr>
        `;
        
        $('#rules-container').append(newRow);
        ruleIndex++;
    });
    
    // Gestion du bouton "Supprimer" pour les règles (délégation d'événements)
    $(document).on('click', '.remove-rule', function() {
        $(this).closest('tr').remove();
    });
    
    // Prévisualisation des règles
    $('#preview-rule').on('click', function() {
        var min_age = $('#preview-min-age').val();
        var respect_manual = $('#preview-respect-manual').is(':checked') ? 1 : 0;
        
        $('.wcad-preview-results').html('<p>' + wcadData.i18n.loading + '</p>');
        $('.wcad-preview-container').show();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wcad_preview_rule',
                security: wcadData.preview_nonce,
                min_age: min_age,
                respect_manual: respect_manual
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var html = '';
                    
                    html += '<div class="wcad-preview-summary">';
                    html += '<p><strong>' + wcadData.i18n.affected_products + ':</strong> ' + data.count + '</p>';
                    html += '<p><strong>' + wcadData.i18n.total_value + ':</strong> ' + wcadData.currency_symbol + data.total_value.toFixed(2) + '</p>';
                    html += '</div>';
                    
                    if (data.products.length > 0) {
                        html += '<h4>' + wcadData.i18n.sample_products + ':</h4>';
                        html += '<ul class="wcad-preview-products">';
                        
                        $.each(data.products, function(index, product) {
                            html += '<li><a href="' + product.link + '">' + product.name + '</a> - ' + wcadData.currency_symbol + product.price + '</li>';
                        });
                        
                        if (data.count > data.products.length) {
                            html += '<li class="wcad-preview-more">' + wcadData.i18n.and_more.replace('%d', (data.count - data.products.length)) + '</li>';
                        }
                        
                        html += '</ul>';
                    }
                    
                    $('.wcad-preview-results').html(html);
                } else {
                    $('.wcad-preview-results').html('<p class="wcad-error">' + wcadData.i18n.error + '</p>');
                }
            },
            error: function() {
                $('.wcad-preview-results').html('<p class="wcad-error">' + wcadData.i18n.error + '</p>');
            }
        });
    });
    
    // Fermer la prévisualisation avec la nouvelle croix
    $(document).on('click', '.wcad-preview-close', function(e) {
        e.preventDefault();
        $('.wcad-preview-container').hide();
    });
}); 