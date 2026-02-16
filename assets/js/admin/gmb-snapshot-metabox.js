jQuery(document).ready(function($) {
    'use strict';
    
    /**
     * Manejador para el botón de actualizar snapshot
     */
    $('#refresh-gmb-snapshot').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var businessId = gmbSnapshotData.businessId;
        
        // Deshabilitar botón y cambiar texto
        $button.prop('disabled', true).text('Actualizando...');
        
        $.ajax({
            url: gmbSnapshotData.ajaxurl,
            type: 'POST',
            data: {
                action: 'refresh_gmb_snapshot',
                business_id: businessId,
                nonce: gmbSnapshotData.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Recargar la página para mostrar los datos actualizados
                    location.reload();
                } else {
                    alert('Error: ' + (response.data.message || 'No se pudo actualizar el snapshot'));
                    $button.prop('disabled', false).text('🔄 Actualizar Snapshot');
                }
            },
            error: function(xhr, status, error) {
                alert('Error de conexión: ' + error);
                $button.prop('disabled', false).text('🔄 Actualizar Snapshot');
            }
        });
    });
    
    /**
     * Manejador para el botón de crear ubicación desde GMB
     */
    $('.create-location-btn').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var locationIndex = $button.data('location-index');
        var businessId = gmbSnapshotData.businessId;
        var $row = $button.closest('tr');
        
        // Confirmar acción
        if (!confirm('¿Deseas crear una nueva ubicación desde estos datos de GMB?')) {
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
                location_index: locationIndex,
                nonce: gmbSnapshotData.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Mostrar mensaje de éxito
                    alert('✓ ' + response.data.message);
                    
                    // Actualizar la fila con el nuevo estado
                    var $statusCell = $row.find('td').eq(4); // Columna "Ficha"
                    $statusCell.html(
                        '<span class="gmb-status-badge status-created">● Creado</span><br>' +
                        '<a href="' + response.data.edit_url + '" class="button button-small" target="_blank">Ver</a>'
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
                alert('Error de conexión al servidor: ' + error);
                $button.prop('disabled', false).text('Crear');
            }
        });
    });
});
