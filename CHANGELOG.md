# Changelog

Todos los cambios relevantes del plugin se documentan en este archivo.

## [1.5.0] - 2026-08-27

### Agregado
- Indicador global de diligenciamiento del perfil de ubicación con porcentaje y semáforo rojo, amarillo o verde.
- Indicadores de completitud por sección para Información, Ubicación, Contacto, Horarios, Características y el catálogo aplicable.
- Capa de presentación específica para clientes que oculta identificadores, nombres de campos de API, payloads y otros detalles técnicos sin retirarlos del backend administrativo.
- Resumen amigable del estado de conexión con Google Business Profile sin exponer Account ID, Location ID, resource names ni datos RAW.
- Selección contextual entre Menú y Servicios usando primero el tipo de catálogo detectado por la sincronización existente y, cuando aún no existe ese dato, la categoría principal como fallback conservador.
- Flujo visual de dos pasos: guardar primero en Lealez y publicar después en Google.

### Modificado
- El perfil frontend de ubicación se reorganizó en grupos más fáciles de navegar y con nombres orientados al usuario final.
- Categoría principal y categorías adicionales pasan a identificarse en frontend como datos publicables en Google, reutilizando el flujo dinámico `categories.list` y el push ya existente del metabox de Información Básica.
- La sección de atributos se presenta como **Características** y conserva la consulta dinámica por categoría y país; no se hardcodean opciones que Google pueda cambiar.
- Los módulos Menú y Servicios se muestran según la aplicabilidad detectada para el tipo de negocio, manteniendo los datos locales existentes aunque cambie la detección.
- Textos de botones y estados en el frontend se traducen a acciones de negocio como **Guardar en Lealez**, **Publicar en Google**, **Actualizar desde Google** y **Historial**.
- El resumen principal dejó de mostrar identificadores internos de Google y ahora prioriza empresa, categoría, dirección, conexión y completitud.
- Navegación lateral mejorada con estado sticky en escritorio, indicadores de completitud y comportamiento responsive.
- Versión del plugin actualizada a `1.5.0`.

### Conservado
- Metaboxes originales, handlers AJAX, nonces, jobs, polling, logs, límites y procesos de publicación existentes.
- Flujo de edición local antes del envío a Google.
- Información técnica completa en el backend administrativo para diagnóstico y soporte.
- Permisos actuales de empresa y ubicación.
- Compatibilidad con widgets Elementor, shortcodes y rutas heredadas.

### Referencia de integración Google
- Las categorías y características disponibles deben consultarse dinámicamente por región e idioma.
- Las características dependen de categoría y país y pueden cambiar con el tiempo.
- Las actualizaciones de ubicación usan máscaras de campos, pero esos nombres técnicos no se exponen al cliente.
- La elegibilidad de menús de alimentos se determina por las capacidades de la ubicación cuando la sincronización las ha detectado.

## [1.4.0] - 2026-07-31

### Agregado
- Integración nativa con Elementor cargada únicamente cuando Elementor está disponible.
- Categoría `Lealez` en el editor de Elementor.
- Seis widgets nativos para el portal frontend:
  - Panel de cuenta.
  - Mis empresas.
  - Perfil de empresa.
  - Mis ubicaciones.
  - Perfil de ubicación.
  - Mi perfil.
- Controles Elementor de ancho, densidad, fondos, superficies, colores, tipografía, rellenos, bordes, sombras y botones.
- Resúmenes visuales para empresa y ubicación, inspirados en la lectura clara de perfiles modernos de directorios sin copiar una interfaz externa.
- Navegación interna del perfil de empresa para Perfil, Equipo, Integraciones y Google Business Profile.
- Migración segura de páginas antiguas basadas en shortcode, con respaldo en `_lealez_pre_elementor_content_backup`.
- Detección diferenciada de Elementor activo, instalado pero inactivo y no instalado.
- Limpieza controlada de páginas frontend heredadas gestionadas por Lealez.
- Redirecciones permanentes desde slugs heredados hacia los perfiles unificados.

### Modificado
- El instalador `Páginas frontend de Lealez` crea páginas con datos nativos en `_elementor_data`; ya no inserta shortcodes en `post_content`.
- Crear o reparar páginas requiere Elementor activo para evitar páginas incompletas.
- `Perfil de empresa` concentra edición general, equipo, integraciones y centro Google en una sola página.
- `Perfil de ubicación` conserva el perfil unificado de la versión 1.3.0 y añade una cabecera visual configurable.
- El estado de cada página se valida por la presencia del widget Elementor esperado.
- El botón de edición abre directamente el editor de Elementor.
- Los estilos del portal aceptan variables configurables desde cada widget sin alterar la lógica funcional.
- El alias interno `business_google` apunta al perfil de empresa para conservar la precarga de recursos y los flujos existentes del centro GMB.
- README actualizado con arquitectura, widgets, migración, pruebas, seguridad y control de cambios de la versión 1.4.0.
- Versión del plugin actualizada a `1.4.0`.

### Retirado
- Creación de páginas frontend independientes para Equipo de empresa, Integraciones de empresa, Google de empresa y Google de ubicación.
- Dependencia del shortcode como contenido de las páginas generadas.

### Conservado
- Shortcodes existentes como API interna y capa de compatibilidad.
- Metaboxes, handlers AJAX, nonces, procesos de guardado, permisos, cron jobs, logs y verificadores existentes.
- Redirección compatible de enlaces antiguos.
- Recuperación de páginas retiradas desde la Papelera de WordPress.

## [1.3.0] - 2026-07-30

### Agregado
- Perfil frontend unificado para `oy_location` dentro de `[lealez_location_editor]`.
- Leyenda y etiquetas por sección/campo: Google, datos de Google, solo Lealez, mixto y control técnico.
- Formulario específico para datos administrativos internos sin exposición a Google.
- Resumen de estados locales, envíos, revisión y aplicación en Google.
- Compatibilidad responsive y anotación de campos durante la creación inicial.

### Modificado
- `Editar ubicación` concentra datos internos, GMB, contenido, interacción y analítica.
- El instalador deja de crear la página independiente `Google de ubicación`.
- Los enlaces existentes de la antigua página redirigen al perfil unificado conservando ubicación y módulo.
- La documentación de publicación distingue guardado local, envío y aprobación de Google.
- Versión del plugin actualizada a `1.3.0`.

### Conservado
- La página `Google de empresa` para OAuth, cuentas y propiedades.
- Los metaboxes, handlers AJAX, nonces, jobs, logs y verificadores originales.
- Las meta keys y funciones existentes de los CPT.

## [1.2.0] - 2026-07-30

### Agregado
- Centros frontend para reutilizar módulos reales de Google Business Profile.
- Puente de permisos limitado a empresas y ubicaciones autorizadas.
- Estados de publicación y revisión de Google.

## [1.1.0] - 2026-07-30

### Agregado
- Portal frontend de empresas, ubicaciones y perfil del usuario.
- Instalador de páginas con shortcodes.
- Archivo y restauración sin eliminación de datos.
