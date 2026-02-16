jQuery(document).ready(function($) {
    'use strict';
    
    /**
     * Manejador para el botón de crear ubicación desde GMB
     */
    $('.create-location-btn').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var businessId = $button.data('business-id');
        var gmbName = $button.data('gmb-name');
        var gmbTitle = $button.data('gmb-title');
        var $row = $button.closest('tr');
        
        // Validar datos
        if (!businessId || !gmbName) {
            alert('Error: Datos incompletos para crear la ubicación.');
            return;
        }
        
        // Confirmar acción
        if (!confirm('¿Deseas crear una nueva ubicación desde estos datos de GMB?\n\nUbicación: ' + gmbTitle)) {
            return;
        }
        
        // Deshabilitar botón y cambiar texto
        $button.prop('disabled', true).text('Creando...');
        
        $.ajax({
            url: gmbSnapshotData.ajaxurl,
            type: 'POST',
            data: {
                action: 'create_location_from_gmb',
                business_id: businessId,
                gmb_name: gmbName,
                gmb_title: gmbTitle,
                nonce: gmbSnapshotData.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Mostrar mensaje de éxito
                    alert('✓ ' + response.data.message);
                    
                    // Actualizar la fila con el nuevo estado
                    var $statusCell = $row.find('td').eq(6); // Columna "Ficha"
                    $statusCell.html(
                        '<div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">' +
                        '<div style="display: flex; align-items: center; gap: 6px;">' +
                        '<span style="display: inline-block; width: 12px; height: 12px; background-color: #46b450; border-radius: 50%;"></span>' +
                        '<span style="font-size: 12px; color: #46b450; font-weight: 600;">Creado</span>' +
                        '</div>' +
                        '<a href="' + response.data.edit_url + '" class="button button-small button-secondary" target="_blank">Ver</a>' +
                        '</div>'
                    );
                    
                    // Opcional: resaltar la fila brevemente
                    $row.css('background-color', '#d4edda');
                    setTimeout(function() {
                        $row.css('background-color', '');
                    }, 2000);
                    
                } else {
                    alert('Error: ' + (response.data.message || 'No se pudo crear la ubicación'));
                    $button.prop('disabled', false).text('Crear');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error AJAX:', {xhr: xhr, status: status, error: error});
                alert('Error de conexión al servidor: ' + error);
                $button.prop('disabled', false).text('Crear');
            }
        });
    });
});
