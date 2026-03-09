<?php
/**
 * Metabox: Horario de Mayor Interés
 *
 * Panel que muestra en qué días y a qué horas el negocio genera mayor interés,
 * usando la Business Profile Performance API como única fuente de verdad para
 * los pesos por día de semana.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * FUENTE DE DATOS Y CÁLCULO
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ✅ PESOS POR DÍA — Business Profile Performance API (automático):
 *    Las 11 métricas disponibles se ponderan por nivel de intención:
 *
 *    Alta intención (×3.0):  CALL_CLICKS, BUSINESS_BOOKINGS, BUSINESS_FOOD_ORDERS
 *    Media-alta (×2.5):      BUSINESS_DIRECTION_REQUESTS
 *    Media (×2.0):           BUSINESS_CONVERSATIONS
 *    Media-baja (×1.5):      WEBSITE_CLICKS, BUSINESS_FOOD_MENU_CLICKS
 *    Baja / pasiva (×0.8):   BUSINESS_IMPRESSIONS_MOBILE_MAPS/SEARCH
 *    Muy baja (×0.6):        BUSINESS_IMPRESSIONS_DESKTOP_MAPS/SEARCH
 *
 *    Se obtienen 90 días de datos, se acumula el score ponderado por día
 *    de semana, se calcula el promedio y se normaliza a 0-100.
 *    El resultado es el "Índice de Interés" de ese día.
 *
 * ❌ DISTRIBUCIÓN HORARIA — No disponible en ninguna API de GBP:
 *    Los "Popular Times" de Google Maps vienen del historial anónimo de
 *    usuarios de Android/Maps y no están expuestos en ningún endpoint oficial.
 *    → La distribución horaria dentro de cada día se define manualmente
 *      usando plantillas por tipo de negocio o sliders hora por hora.
 *      El gráfico aplica el peso del día sobre la curva horaria elegida.
 *
 * META GUARDADA: gmb_peak_hours (JSON sin guión bajo — convención oy_location)
 * ─────────────────────────────────────────────────────────────────────────
 * {
 *   "hours": {                          // Distribución horaria (0-100 por hora)
 *     "monday":    [0,0,…,65,80,…,0],   // 24 valores, índice = hora (0-23)
 *     …
 *     "sunday":    […]
 *   },
 *   "day_weights": {                    // Índice de interés normalizado (0-100)
 *     "monday": 43, "friday": 100, …    // 100 = día más activo del negocio
 *   },
 *   "day_scores_raw": {                 // Score bruto antes de normalizar
 *     "monday": 4.21, "friday": 9.84, …
 *   },
 *   "metric_breakdown": {               // Contribución de cada grupo al score
 *     "monday":  { "actions": 2.8, "impressions": 1.4 },
 *     …
 *   },
 *   "avg_stay_min": 45,
 *   "avg_stay_max": 90,
 *   "last_computed": "2026-03-09T10:00:00Z",
 *   "period": { "start": "2025-12-10", "end": "2026-03-09" },
 *   "metrics_used": 8,
 *   "source": "api"
 * }
 *
 * META ADICIONAL: gmb_busiest_day_of_week (string), gmb_busiest_hour_of_day (int)
 *
 * ARCHIVO: includes/cpts/metaboxes/class-oy-location-gmb-busyhours-metabox.php
 *
 * @package Lealez
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OY_Location_GMB_BusyHours_Metabox
 *
 * AJAX handlers:
 *  - wp_ajax_oy_gmb_busy_compute  → Computa índice de interés por día desde Performance API
 *  - wp_ajax_oy_gmb_busy_save     → Guarda gmb_peak_hours en post meta
 */
if ( ! class_exists( 'OY_Location_GMB_BusyHours_Metabox' ) ) :

class OY_Location_GMB_BusyHours_Metabox {

	const NONCE_ACTION = 'oy_gmb_busyhours_nonce';
	const META_KEY     = 'gmb_peak_hours';

	/**
	 * Ponderación de cada métrica para el cálculo del Índice de Interés.
	 * Mayor peso = mayor intención de interacción real con el negocio.
	 *
	 * @var array
	 */
	private static $metric_weights = array(
		// ── Alta intención (acción directa) ──────────────────────────────────
		'CALL_CLICKS'                        => 3.0,
		'BUSINESS_BOOKINGS'                  => 3.0,
		'BUSINESS_FOOD_ORDERS'               => 3.0,
		// ── Media-alta (intención de visita física) ───────────────────────────
		'BUSINESS_DIRECTION_REQUESTS'        => 2.5,
		// ── Media (contacto directo) ──────────────────────────────────────────
		'BUSINESS_CONVERSATIONS'             => 2.0,
		// ── Media-baja (investigación activa) ─────────────────────────────────
		'WEBSITE_CLICKS'                     => 1.5,
		'BUSINESS_FOOD_MENU_CLICKS'          => 1.5,
		// ── Baja / pasiva (exposición en móvil) ───────────────────────────────
		'BUSINESS_IMPRESSIONS_MOBILE_MAPS'   => 0.8,
		'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' => 0.8,
		// ── Muy baja / pasiva (exposición en desktop) ─────────────────────────
		'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'   => 0.6,
		'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 0.6,
	);

	/**
	 * Grupos de métricas para el desglose visual.
	 * @var array
	 */
	private static $metric_groups = array(
		'high_intent'  => array(
			'label'   => 'Acciones directas',
			'color'   => '#e53935',
			'metrics' => array( 'CALL_CLICKS', 'BUSINESS_BOOKINGS', 'BUSINESS_FOOD_ORDERS' ),
		),
		'visit_intent' => array(
			'label'   => 'Intención de visita',
			'color'   => '#f57c00',
			'metrics' => array( 'BUSINESS_DIRECTION_REQUESTS', 'BUSINESS_CONVERSATIONS' ),
		),
		'research'     => array(
			'label'   => 'Investigación activa',
			'color'   => '#1976d2',
			'metrics' => array( 'WEBSITE_CLICKS', 'BUSINESS_FOOD_MENU_CLICKS' ),
		),
		'impressions'  => array(
			'label'   => 'Visibilidad pasiva',
			'color'   => '#757575',
			'metrics' => array(
				'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
				'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
				'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
				'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
			),
		),
	);

	/**
	 * Plantillas de distribución horaria (24 valores, índice = hora 0-23, escala 0-100).
	 * Define la FORMA de la curva dentro del día. El peso del día la escala verticalmente.
	 *
	 * @var array
	 */
	private static $templates = array(
		'comercio'    => array( 0, 0, 0, 0, 0, 0,  5, 20, 45, 65, 75, 70, 55, 50, 60, 70, 65, 55, 40, 25, 10,  5,  0, 0 ),
		'restaurante' => array( 0, 0, 0, 0, 0, 0,  5, 10, 25, 35, 55, 75, 80, 65, 40, 50, 65, 75, 85, 75, 50, 25,  5, 0 ),
		'cafe'        => array( 0, 0, 0, 0, 0, 0, 25, 65, 80, 75, 65, 55, 45, 35, 25, 20, 15, 10,  5,  0,  0,  0,  0, 0 ),
		'nocturno'    => array( 0, 0, 0, 0, 0, 0,  0,  0,  5, 10, 15, 20, 25, 25, 30, 35, 45, 60, 75, 85, 90, 80, 60, 30 ),
		'continuo'    => array( 0, 0, 0, 0, 0, 0,  5, 20, 45, 55, 55, 55, 55, 55, 55, 55, 50, 45, 35, 20, 10,  5,  0,  0 ),
		'oficina'     => array( 0, 0, 0, 0, 0, 0,  0,  5, 20, 70, 80, 75, 60, 55, 65, 70, 65, 45, 20,  5,  0,  0,  0,  0 ),
		'gimnasio'    => array( 0, 0, 0, 0, 0, 15, 55, 75, 65, 45, 35, 35, 30, 30, 35, 40, 55, 70, 65, 50, 30, 10,  0,  0 ),
	);

	// ── Constructor / Hooks ──────────────────────────────────────────────────

	public function __construct() {
		add_action( 'add_meta_boxes',        array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_oy_gmb_busy_compute', array( $this, 'ajax_compute_interest_index' ) );
		add_action( 'wp_ajax_oy_gmb_busy_save',    array( $this, 'ajax_save_peak_hours' ) );
	}

	// ── Registration ─────────────────────────────────────────────────────────

	public function register_meta_box() {
		add_meta_box(
			'oy_location_gmb_busyhours',
			__( '🕐 Horario de Mayor Interés', 'lealez' ),
			array( $this, 'render_meta_box' ),
			'oy_location',
			'normal',
			'default'
		);
	}

	// ── Enqueue ──────────────────────────────────────────────────────────────

	public function enqueue_scripts( $hook ) {
		global $post;

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( ! $post || 'oy_location' !== $post->post_type ) {
			return;
		}

		// ── Chart.js 4.4.3 — LOCAL (mismo patrón que Performance metabox) ────
		if ( ! wp_script_is( 'chartjs-v4', 'registered' ) ) {
			if ( defined( 'LEALEZ_ASSETS_URL' ) ) {
				$chartjs_url = LEALEZ_ASSETS_URL . 'js/vendor/chart.umd.min.js';
			} else {
				$plugin_root = dirname( dirname( dirname( dirname( __FILE__ ) ) ) );
				$chartjs_url = plugins_url( 'assets/js/vendor/chart.umd.min.js', $plugin_root . '/index.php' );
			}
			wp_register_script( 'chartjs-v4', $chartjs_url, array(), '4.4.3', true );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[OyBusyHours] Chart.js URL: ' . $chartjs_url );
			}
		}
		wp_enqueue_script( 'chartjs-v4' );

		// ── Script inline (no requiere archivo adicional) ─────────────────────
		wp_register_script( 'oy-busyhours', false, array( 'jquery', 'chartjs-v4' ), '2.0.0', true );
		wp_enqueue_script( 'oy-busyhours' );

		// ── Leer datos guardados ──────────────────────────────────────────────
		$saved_raw   = get_post_meta( $post->ID, self::META_KEY, true );
		$saved_data  = ( ! empty( $saved_raw ) ) ? json_decode( $saved_raw, true ) : null;

		// Determinar si GMB está conectado para habilitar el compute
		$business_id   = get_post_meta( $post->ID, 'parent_business_id', true );
		$location_id   = get_post_meta( $post->ID, 'gmb_location_id', true );
		$gmb_connected = false;
		if ( $business_id ) {
			$flag          = get_post_meta( $business_id, '_gmb_connected', true );
			$has_refresh   = (bool) get_post_meta( $business_id, 'gmb_refresh_token', true );
			$gmb_connected = $flag || $has_refresh;
		}

		wp_localize_script(
			'oy-busyhours',
			'oyBusyConfig',
			array(
				'postId'       => $post->ID,
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'savedData'    => $saved_data,
				'templates'    => self::$templates,
				'metricGroups' => self::$metric_groups,
				'gmbConnected' => $gmb_connected,
				'hasLocationId'=> ! empty( $location_id ),
			)
		);

		wp_add_inline_script( 'oy-busyhours', $this->get_inline_js() );
		wp_add_inline_style( 'wp-admin', $this->get_inline_css() );
	}

	// ── Render metabox HTML ──────────────────────────────────────────────────

	public function render_meta_box( $post ) {
		$location_id   = get_post_meta( $post->ID, 'gmb_location_id', true );
		$business_id   = get_post_meta( $post->ID, 'parent_business_id', true );
		$gmb_connected = false;

		if ( $business_id ) {
			$flag          = get_post_meta( $business_id, '_gmb_connected', true );
			$has_refresh   = (bool) get_post_meta( $business_id, 'gmb_refresh_token', true );
			$gmb_connected = $flag || $has_refresh;
		}

		$saved_raw  = get_post_meta( $post->ID, self::META_KEY, true );
		$saved_data = ( ! empty( $saved_raw ) ) ? json_decode( $saved_raw, true ) : null;
		$has_data   = ! empty( $saved_data['day_weights'] );

		// Calcular día pico guardado
		$busiest_day = get_post_meta( $post->ID, 'gmb_busiest_day_of_week', true );
		$day_labels  = array(
			'monday' => 'Lunes', 'tuesday' => 'Martes', 'wednesday' => 'Miércoles',
			'thursday' => 'Jueves', 'friday' => 'Viernes', 'saturday' => 'Sábado', 'sunday' => 'Domingo',
		);
		?>
		<div id="oy-busy-wrap">

			<?php if ( ! $gmb_connected ) : ?>
			<div class="oy-busy-notice oy-busy-notice--warn">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'El negocio padre no tiene Google Business Profile conectado. Conecta GMB primero para calcular el índice de interés.', 'lealez' ); ?>
			</div>
			<?php elseif ( ! $location_id ) : ?>
			<div class="oy-busy-notice oy-busy-notice--warn">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Esta ubicación no está vinculada a una propiedad GMB. Configura el GMB Location ID primero.', 'lealez' ); ?>
			</div>
			<?php endif; ?>

			<!-- ── Cabecera de estado ── -->
			<?php if ( $has_data && ! empty( $saved_data['last_computed'] ) ) : ?>
			<div class="oy-busy-computed-header">
				<div class="oy-busy-computed-header__left">
					<span class="oy-busy-source-pill oy-busy-source-pill--api">
						<span class="dashicons dashicons-chart-bar" style="font-size:13px;vertical-align:middle;margin-right:3px;"></span>
						<?php esc_html_e( 'Índice calculado desde Performance API', 'lealez' ); ?>
					</span>
					<?php
					$metrics_used = isset( $saved_data['metrics_used'] ) ? (int) $saved_data['metrics_used'] : 0;
					$period_start = isset( $saved_data['period']['start'] ) ? $saved_data['period']['start'] : '';
					$period_end   = isset( $saved_data['period']['end'] )   ? $saved_data['period']['end']   : '';
					if ( $period_start && $period_end ) :
					?>
					<span class="oy-busy-period-label">
						<?php echo esc_html( $period_start . ' → ' . $period_end ); ?>
						<?php if ( $metrics_used ) : ?>
						· <?php echo esc_html( $metrics_used ); ?> <?php esc_html_e( 'métricas', 'lealez' ); ?>
					<?php endif; ?>
					</span>
					<?php endif; ?>
				</div>
				<div class="oy-busy-computed-header__right">
					<?php if ( $busiest_day && isset( $day_labels[ $busiest_day ] ) ) : ?>
					<span class="oy-busy-peak-badge">
						📍 <?php echo esc_html( $day_labels[ $busiest_day ] ); ?> <?php esc_html_e( 'es el día de mayor interés', 'lealez' ); ?>
					</span>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

			<!-- ── Toolbar ── -->
			<div class="oy-busy-toolbar">
				<div class="oy-busy-toolbar__left">

					<div class="oy-busy-field-group">
						<label for="oy-busy-template-sel">
							<span class="dashicons dashicons-clock" style="font-size:14px;vertical-align:middle;margin-right:2px;"></span>
							<?php esc_html_e( 'Forma horaria:', 'lealez' ); ?>
						</label>
						<select id="oy-busy-template-sel" class="oy-busy-select">
							<option value=""><?php esc_html_e( '— Tipo de negocio —', 'lealez' ); ?></option>
							<option value="comercio"><?php esc_html_e( 'Comercio / Tienda', 'lealez' ); ?></option>
							<option value="restaurante"><?php esc_html_e( 'Restaurante', 'lealez' ); ?></option>
							<option value="cafe"><?php esc_html_e( 'Café / Desayunos', 'lealez' ); ?></option>
							<option value="nocturno"><?php esc_html_e( 'Nocturno / Bar', 'lealez' ); ?></option>
							<option value="continuo"><?php esc_html_e( 'Horario continuo', 'lealez' ); ?></option>
							<option value="oficina"><?php esc_html_e( 'Oficina / Servicios', 'lealez' ); ?></option>
							<option value="gimnasio"><?php esc_html_e( 'Gimnasio / Fitness', 'lealez' ); ?></option>
						</select>
					</div>

					<div class="oy-busy-field-group">
						<label><?php esc_html_e( 'Permanencia estimada:', 'lealez' ); ?></label>
						<input type="number" id="oy-busy-stay-min" min="5" max="480" step="5" value="45" class="small-text" style="width:60px;">
						<span>–</span>
						<input type="number" id="oy-busy-stay-max" min="5" max="480" step="5" value="90" class="small-text" style="width:60px;">
						<span class="description"><?php esc_html_e( 'min', 'lealez' ); ?></span>
					</div>

				</div>
				<div class="oy-busy-toolbar__right">
					<?php if ( $gmb_connected && $location_id ) : ?>
					<button type="button" id="oy-busy-compute-btn" class="button button-primary">
						<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:3px;margin-right:2px;"></span>
						<?php esc_html_e( 'Calcular índice desde GMB', 'lealez' ); ?>
					</button>
					<?php endif; ?>
					<button type="button" id="oy-busy-save-btn" class="button button-secondary">
						<span class="dashicons dashicons-saved" style="vertical-align:middle;margin-top:3px;margin-right:2px;"></span>
						<?php esc_html_e( 'Guardar', 'lealez' ); ?>
					</button>
				</div>
			</div>

			<!-- Status bar -->
			<div id="oy-busy-status-msg" class="oy-busy-status" style="display:none;"></div>

			<!-- ── Panel oscuro: tabs de días + gráfico ── -->
			<div class="oy-busy-dark-panel">

				<!-- Tabs de días con mini-barra de índice -->
				<div class="oy-busy-day-tabs" id="oy-busy-day-tabs">
					<?php
					$days  = array(
						'monday'    => 'LUN',
						'tuesday'   => 'MAR',
						'wednesday' => 'MIÉ',
						'thursday'  => 'JUE',
						'friday'    => 'VIE',
						'saturday'  => 'SÁB',
						'sunday'    => 'DOM',
					);
					$first = true;
					foreach ( $days as $key => $label ) :
					?>
					<button type="button"
						class="oy-busy-day-tab<?php echo $first ? ' oy-busy-day-tab--active' : ''; ?>"
						data-day="<?php echo esc_attr( $key ); ?>">
						<span class="oy-busy-day-bar-track">
							<span class="oy-busy-day-bar" id="oy-day-bar-<?php echo esc_attr( $key ); ?>" style="height:5%;"></span>
						</span>
						<span class="oy-busy-day-name"><?php echo esc_html( $label ); ?></span>
						<span class="oy-busy-day-index" id="oy-day-index-<?php echo esc_attr( $key ); ?>">—</span>
					</button>
					<?php $first = false; endforeach; ?>
				</div>

				<!-- Estado de concurrencia del día -->
				<div class="oy-busy-status-row">
					<span class="oy-busy-live-dot" id="oy-busy-live-dot"></span>
					<div>
						<div class="oy-busy-status-title" id="oy-busy-status-title">
							<?php esc_html_e( 'Sin datos — calcule el índice desde GMB', 'lealez' ); ?>
						</div>
						<div class="oy-busy-status-subtitle" id="oy-busy-status-subtitle">
							<?php esc_html_e( 'Haga clic en "Calcular índice desde GMB" y luego seleccione la forma horaria para este negocio', 'lealez' ); ?>
						</div>
					</div>
					<!-- Índice del día visible -->
					<div class="oy-busy-day-score-badge" id="oy-busy-day-score-badge" style="display:none;">
						<span class="oy-busy-score-num" id="oy-busy-score-num">0</span>
						<span class="oy-busy-score-label"><?php esc_html_e( 'Índice', 'lealez' ); ?></span>
					</div>
				</div>

				<!-- Gráfico de barras horario -->
				<div class="oy-busy-chart-container">
					<canvas id="oy-busy-chart"></canvas>
				</div>

				<!-- Desglose de métricas del día seleccionado -->
				<div class="oy-busy-breakdown" id="oy-busy-breakdown" style="display:none;">
					<span class="oy-busy-breakdown__label"><?php esc_html_e( 'Composición del índice:', 'lealez' ); ?></span>
					<div class="oy-busy-breakdown__bars" id="oy-busy-breakdown-bars"></div>
				</div>

				<!-- Permanencia -->
				<div class="oy-busy-stay-row">
					<span class="dashicons dashicons-clock" style="margin-right:5px;opacity:.7;"></span>
					<span><?php esc_html_e( 'Permanencia estimada:', 'lealez' ); ?></span>
					<strong id="oy-busy-stay-display" style="margin-left:5px;">—</strong>
				</div>

			</div><!-- .oy-busy-dark-panel -->

			<!-- ── Edit mode: ajuste fino de la curva horaria ── -->
			<div class="oy-busy-edit-header">
				<label class="oy-busy-toggle-wrap">
					<input type="checkbox" id="oy-busy-edit-toggle">
					<span class="oy-busy-toggle-label">
						<span class="dashicons dashicons-edit" style="font-size:14px;vertical-align:middle;margin-right:3px;"></span>
						<?php esc_html_e( 'Ajustar distribución horaria manualmente', 'lealez' ); ?>
					</span>
				</label>
				<span class="description" style="margin-left:10px;">
					<?php esc_html_e( 'Modifica hora a hora la curva del día seleccionado (el índice del día no cambia, solo la distribución dentro del día)', 'lealez' ); ?>
				</span>
			</div>

			<div id="oy-busy-edit-area" style="display:none;">
				<div class="oy-busy-sliders-grid" id="oy-busy-sliders-grid"></div>
				<p class="description" style="margin:6px 0 0;">
					<?php esc_html_e( 'Los cambios se reflejan en el gráfico en tiempo real. Recuerda guardar cuando termines.', 'lealez' ); ?>
				</p>
			</div>

			<!-- ── Nota técnica ── -->
			<div class="oy-busy-footer">
				<strong><?php esc_html_e( '¿Cómo funciona el Índice de Interés?', 'lealez' ); ?></strong>
				<?php esc_html_e( 'Se obtienen 90 días de datos de la Performance API y se calcula un score ponderado por día de semana, donde las acciones de alta intención (llamadas, reservas, cómo llegar) pesan más que las impresiones pasivas. El día con mayor score queda en 100 y los demás se normalizan proporcionalmente.', 'lealez' ); ?>
				<br>
				<em><?php esc_html_e( 'La distribución horaria dentro de cada día no está disponible en ninguna API de Google Business Profile. Se estima mediante plantillas por tipo de negocio.', 'lealez' ); ?></em>
			</div>

		</div><!-- #oy-busy-wrap -->
		<?php
	}

	// ── AJAX: Compute Interest Index from Performance API ────────────────────

	/**
	 * Calcula el Índice de Interés por día de semana usando TODAS las métricas
	 * disponibles de la Performance API, ponderadas por nivel de intención.
	 *
	 * Proceso:
	 *  1. Solicita 90 días de todas las métricas disponibles en un solo request.
	 *  2. Por cada día del período: calcula score = Σ(valor_métrica × peso_métrica).
	 *  3. Agrupa scores por día de la semana → promedio por DOW.
	 *  4. Normaliza a 0-100 (DOW con mayor promedio = 100).
	 *  5. Devuelve pesos + desglose por grupo de métricas.
	 *
	 * Action: oy_gmb_busy_compute
	 */
	public function ajax_compute_interest_index() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
		}

		$location_name = get_post_meta( $post_id, 'gmb_location_id', true );
		$business_id   = get_post_meta( $post_id, 'parent_business_id', true );

		if ( ! $location_name || ! $business_id ) {
			wp_send_json_error( array( 'message' => __( 'Faltan datos GMB (gmb_location_id o parent_business_id).', 'lealez' ) ) );
		}

		if ( ! class_exists( 'Lealez_GMB_API' ) ) {
			wp_send_json_error( array( 'message' => __( 'Clase Lealez_GMB_API no encontrada.', 'lealez' ) ) );
		}

		// ── Rango: 90 días hasta ayer ─────────────────────────────────────────
		$end_ts   = strtotime( 'yesterday' );
		$start_ts = $end_ts - ( 89 * DAY_IN_SECONDS );

		$start_date = array(
			'year'  => (int) gmdate( 'Y', $start_ts ),
			'month' => (int) gmdate( 'n', $start_ts ),
			'day'   => (int) gmdate( 'j', $start_ts ),
		);
		$end_date = array(
			'year'  => (int) gmdate( 'Y', $end_ts ),
			'month' => (int) gmdate( 'n', $end_ts ),
			'day'   => (int) gmdate( 'j', $end_ts ),
		);

		// Todas las métricas definidas en self::$metric_weights
		$all_metrics = array_keys( self::$metric_weights );

		$result = Lealez_GMB_API::get_location_performance_metrics(
			$business_id,
			$location_name,
			$all_metrics,
			$start_date,
			$end_date,
			true // usar caché (TTL 6h definido en el método API)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			) );
		}

		// ── Estructura de acumulación ─────────────────────────────────────────
		// gmdate('w'): 0=Sunday … 6=Saturday
		$dow_map = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );

		// scores_by_date[date_str][metric_key] = valor_diario
		$scores_by_date = array();
		// Qué métricas realmente devolvió la API (algunas pueden tener todo 0 o no existir)
		$metrics_received = array();

		// ── Parsear multiDailyMetricTimeSeries ────────────────────────────────
		$outer_list = isset( $result['multiDailyMetricTimeSeries'] )
			? (array) $result['multiDailyMetricTimeSeries']
			: array();

		foreach ( $outer_list as $outer_item ) {
			if ( ! is_array( $outer_item ) ) {
				continue;
			}

			$inner_list = isset( $outer_item['dailyMetricTimeSeries'] )
				? (array) $outer_item['dailyMetricTimeSeries']
				: array();

			foreach ( $inner_list as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$metric_key = isset( $item['dailyMetric'] ) ? strtoupper( (string) $item['dailyMetric'] ) : '';

				// Solo procesar métricas conocidas y ponderadas
				if ( ! isset( self::$metric_weights[ $metric_key ] ) ) {
					continue;
				}

				$dated_values = isset( $item['timeSeries']['datedValues'] )
					? (array) $item['timeSeries']['datedValues']
					: array();

				$metric_has_data = false;

				foreach ( $dated_values as $dv ) {
					if ( ! is_array( $dv ) || ! isset( $dv['date'] ) ) {
						continue;
					}

					$d        = $dv['date'];
					$date_str = sprintf(
						'%04d-%02d-%02d',
						isset( $d['year'] )  ? (int) $d['year']  : 0,
						isset( $d['month'] ) ? (int) $d['month'] : 0,
						isset( $d['day'] )   ? (int) $d['day']   : 0
					);

					// API devuelve value como string; ausencia del key = 0
					$val = 0;
					if ( array_key_exists( 'value', $dv ) && null !== $dv['value'] ) {
						$val = (int) $dv['value'];
					}

					if ( ! isset( $scores_by_date[ $date_str ] ) ) {
						$scores_by_date[ $date_str ] = array();
					}
					$scores_by_date[ $date_str ][ $metric_key ] = $val;

					if ( $val > 0 ) {
						$metric_has_data = true;
					}
				}

				if ( $metric_has_data ) {
					$metrics_received[] = $metric_key;
				}
			}
		}

		$metrics_received = array_unique( $metrics_received );

		if ( empty( $scores_by_date ) ) {
			wp_send_json_error( array(
				'message' => __( 'No se recibieron datos de la Performance API. Verifica que el scope OAuth businessprofileperformance.readonly esté habilitado y que existan datos para este período.', 'lealez' ),
			) );
		}

		// ── Acumular score ponderado por día de semana ────────────────────────
		$dow_scores      = array_fill_keys( $dow_map, 0.0 );
		$dow_counts      = array_fill_keys( $dow_map, 0 );
		// Desglose por grupo: $dow_group_scores[dow][group_key] = score_acumulado
		$dow_group_scores = array();
		foreach ( $dow_map as $dk ) {
			$dow_group_scores[ $dk ] = array(
				'high_intent'  => 0.0,
				'visit_intent' => 0.0,
				'research'     => 0.0,
				'impressions'  => 0.0,
			);
		}

		// Mapear métrica → grupo para lookup rápido
		$metric_to_group = array();
		foreach ( self::$metric_groups as $gk => $gd ) {
			foreach ( $gd['metrics'] as $mk ) {
				$metric_to_group[ $mk ] = $gk;
			}
		}

		foreach ( $scores_by_date as $date_str => $metric_vals ) {
			$ts      = strtotime( $date_str );
			$dow_idx = (int) gmdate( 'w', $ts ); // 0-6
			$dow_key = $dow_map[ $dow_idx ];

			$day_score = 0.0;
			foreach ( $metric_vals as $mk => $val ) {
				if ( ! isset( self::$metric_weights[ $mk ] ) ) {
					continue;
				}
				$weighted           = (float) $val * self::$metric_weights[ $mk ];
				$day_score         += $weighted;
				$group              = $metric_to_group[ $mk ] ?? 'impressions';
				$dow_group_scores[ $dow_key ][ $group ] += $weighted;
			}

			$dow_scores[ $dow_key ] += $day_score;
			$dow_counts[ $dow_key ] += 1;
		}

		// ── Calcular promedios por DOW ────────────────────────────────────────
		$dow_averages       = array();
		$dow_group_averages = array();

		foreach ( $dow_map as $dk ) {
			$cnt = max( 1, $dow_counts[ $dk ] );
			$dow_averages[ $dk ] = round( $dow_scores[ $dk ] / $cnt, 4 );

			$dow_group_averages[ $dk ] = array();
			foreach ( $dow_group_scores[ $dk ] as $gk => $gs ) {
				$dow_group_averages[ $dk ][ $gk ] = round( $gs / $cnt, 4 );
			}
		}

		// ── Normalizar a 0-100 ────────────────────────────────────────────────
		$max_avg = max( $dow_averages );
		if ( $max_avg <= 0 ) {
			wp_send_json_error( array(
				'message' => __( 'Todos los indicadores tienen valor cero para este período. No hay suficiente actividad registrada.', 'lealez' ),
			) );
		}

		$day_weights     = array();
		$group_breakdown = array();

		foreach ( $dow_averages as $dk => $avg ) {
			$day_weights[ $dk ] = (int) round( $avg / $max_avg * 100 );

			// Normalizar desglose de grupos al mismo factor
			$group_breakdown[ $dk ] = array();
			foreach ( $dow_group_averages[ $dk ] as $gk => $gav ) {
				$group_breakdown[ $dk ][ $gk ] = round( $gav / $max_avg * 100, 1 );
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[OyBusyHours] Índice de interés por DOW: ' . wp_json_encode( $day_weights, JSON_UNESCAPED_UNICODE ) );
			error_log( '[OyBusyHours] Métricas con datos: ' . implode( ', ', $metrics_received ) );
			error_log( '[OyBusyHours] Días procesados en BD: ' . count( $scores_by_date ) );
		}

		$period = array(
			'start' => sprintf( '%04d-%02d-%02d', $start_date['year'], $start_date['month'], $start_date['day'] ),
			'end'   => sprintf( '%04d-%02d-%02d', $end_date['year'],   $end_date['month'],   $end_date['day'] ),
		);

		wp_send_json_success( array(
			'day_weights'     => $day_weights,
			'scores_raw'      => $dow_averages,
			'group_breakdown' => $group_breakdown,
			'metrics_used'    => count( $metrics_received ),
			'metrics_list'    => $metrics_received,
			'days_processed'  => count( $scores_by_date ),
			'period'          => $period,
			'computed_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
		) );
	}

	// ── AJAX: Save peak hours to post meta ───────────────────────────────────

	/**
	 * Guarda el JSON de horas pico en el meta del post.
	 * Actualiza también gmb_busiest_day_of_week y gmb_busiest_hour_of_day.
	 *
	 * Action: oy_gmb_busy_save
	 */
	public function ajax_save_peak_hours() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permiso denegado.', 'lealez' ) ), 403 );
		}

		$post_id   = absint( $_POST['post_id'] ?? 0 );
		$peak_json = wp_unslash( $_POST['peak_data'] ?? '' );

		if ( ! $post_id || 'oy_location' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Post ID inválido.', 'lealez' ) ) );
		}

		if ( empty( $peak_json ) ) {
			wp_send_json_error( array( 'message' => __( 'No se recibieron datos.', 'lealez' ) ) );
		}

		$peak_data = json_decode( $peak_json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => __( 'JSON inválido recibido.', 'lealez' ) ) );
		}

		// ── Sanitizar distribución horaria ────────────────────────────────────
		$day_keys    = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		$clean_hours = array();
		$hours_input = isset( $peak_data['hours'] ) ? (array) $peak_data['hours'] : array();

		foreach ( $day_keys as $dk ) {
			$clean_hours[ $dk ] = array();
			$day_arr = isset( $hours_input[ $dk ] ) ? (array) $hours_input[ $dk ] : array();
			for ( $h = 0; $h < 24; $h++ ) {
				$clean_hours[ $dk ][] = isset( $day_arr[ $h ] )
					? max( 0, min( 100, (int) $day_arr[ $h ] ) )
					: 0;
			}
		}

		// ── Sanitizar pesos ───────────────────────────────────────────────────
		$clean_weights = array();
		$weights_input = isset( $peak_data['day_weights'] ) ? (array) $peak_data['day_weights'] : array();
		foreach ( $day_keys as $dk ) {
			$clean_weights[ $dk ] = isset( $weights_input[ $dk ] )
				? max( 0, min( 100, (int) $weights_input[ $dk ] ) )
				: 0;
		}

		// ── Sanitizar desglose de grupos ──────────────────────────────────────
		$clean_breakdown  = array();
		$breakdown_input  = isset( $peak_data['metric_breakdown'] ) ? (array) $peak_data['metric_breakdown'] : array();
		$valid_groups     = array( 'high_intent', 'visit_intent', 'research', 'impressions' );
		foreach ( $day_keys as $dk ) {
			$clean_breakdown[ $dk ] = array();
			$dbd = isset( $breakdown_input[ $dk ] ) ? (array) $breakdown_input[ $dk ] : array();
			foreach ( $valid_groups as $gk ) {
				$clean_breakdown[ $dk ][ $gk ] = isset( $dbd[ $gk ] )
					? max( 0, (float) $dbd[ $gk ] )
					: 0.0;
			}
		}

		// ── Período ───────────────────────────────────────────────────────────
		$period = array();
		if ( isset( $peak_data['period'] ) && is_array( $peak_data['period'] ) ) {
			$period['start'] = sanitize_text_field( $peak_data['period']['start'] ?? '' );
			$period['end']   = sanitize_text_field( $peak_data['period']['end']   ?? '' );
		}

		// ── Payload final ─────────────────────────────────────────────────────
		$clean_data = array(
			'hours'            => $clean_hours,
			'day_weights'      => $clean_weights,
			'day_scores_raw'   => array(), // se guarda vacío si no viene
			'metric_breakdown' => $clean_breakdown,
			'avg_stay_min'     => max( 5, min( 480, (int) ( $peak_data['avg_stay_min'] ?? 45 ) ) ),
			'avg_stay_max'     => max( 5, min( 480, (int) ( $peak_data['avg_stay_max'] ?? 90 ) ) ),
			'last_computed'    => sanitize_text_field( $peak_data['last_computed'] ?? '' ),
			'period'           => $period,
			'metrics_used'     => max( 0, (int) ( $peak_data['metrics_used'] ?? 0 ) ),
			'source'           => 'api',
		);

		// Scores raw opcionales
		if ( isset( $peak_data['day_scores_raw'] ) && is_array( $peak_data['day_scores_raw'] ) ) {
			foreach ( $day_keys as $dk ) {
				$clean_data['day_scores_raw'][ $dk ] = isset( $peak_data['day_scores_raw'][ $dk ] )
					? round( (float) $peak_data['day_scores_raw'][ $dk ], 4 )
					: 0.0;
			}
		}

		$json_to_save = wp_json_encode( $clean_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( $post_id, self::META_KEY, $json_to_save );

		// ── Meta fields individuales ──────────────────────────────────────────
		$busiest_day = '';
		$max_weight  = -1;
		foreach ( $clean_data['day_weights'] as $dk => $w ) {
			if ( $w > $max_weight ) {
				$max_weight  = $w;
				$busiest_day = $dk;
			}
		}

		if ( $busiest_day ) {
			update_post_meta( $post_id, 'gmb_busiest_day_of_week', $busiest_day );

			// Hora pico del día más activo (score = hours × day_weight)
			$day_hours    = $clean_data['hours'][ $busiest_day ] ?? array();
			$best_val     = -1;
			$busiest_hour = 0;
			foreach ( $day_hours as $h => $v ) {
				$scaled = (int) round( $v * ( $max_weight / 100 ) );
				if ( $scaled > $best_val ) {
					$best_val     = $scaled;
					$busiest_hour = $h;
				}
			}
			update_post_meta( $post_id, 'gmb_busiest_hour_of_day', $busiest_hour );
		}

		wp_send_json_success( array(
			'message'     => __( 'Horario de mayor interés guardado correctamente.', 'lealez' ),
			'busiest_day' => $busiest_day,
		) );
	}

	// ── Inline JavaScript ────────────────────────────────────────────────────

	private function get_inline_js() {
		return <<<'JSEOF'
(function ($) {
    'use strict';

    /* =====================================================================
     * OyBusyHours v2 — Horario de Mayor Interés
     * Pesos de días: 100% desde Performance API (no manuales)
     * Distribución horaria: plantillas por tipo de negocio + edición manual
     * ===================================================================== */
    var OyBusyHours = {

        config:     null,
        chart:      null,
        currentDay: 'monday',
        data:       null,
        editMode:   false,

        DAYS: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],

        HOUR_LABELS: [
            '12a','1a','2a','3a','4a','5a',
            '6a','7a','8a','9a','10a','11a',
            '12p','1p','2p','3p','4p','5p',
            '6p','7p','8p','9p','10p','11p'
        ],

        DOW_LABELS: {
            monday:'Lunes', tuesday:'Martes', wednesday:'Miércoles',
            thursday:'Jueves', friday:'Viernes', saturday:'Sábado', sunday:'Domingo'
        },

        GROUP_COLORS: {
            high_intent:  '#e53935',
            visit_intent: '#f57c00',
            research:     '#1976d2',
            impressions:  '#757575'
        },

        GROUP_LABELS: {
            high_intent:  'Acciones directas',
            visit_intent: 'Intención de visita',
            research:     'Investigación activa',
            impressions:  'Visibilidad pasiva'
        },

        // ── Inicialización ──────────────────────────────────────────────────

        init: function (config) {
            this.config = config;

            if (config.savedData && config.savedData.day_weights) {
                this.data = config.savedData;
                if (!this.data.hours)          this.data.hours          = this.buildEmptyHours();
                if (!this.data.metric_breakdown) this.data.metric_breakdown = {};
                if (!this.data.avg_stay_min)   this.data.avg_stay_min   = 45;
                if (!this.data.avg_stay_max)   this.data.avg_stay_max   = 90;
            } else {
                this.data = this.buildDefaultData();
            }

            $('#oy-busy-stay-min').val(this.data.avg_stay_min || 45);
            $('#oy-busy-stay-max').val(this.data.avg_stay_max || 90);

            this.bindEvents();
            this.updateAllDayTabs();
            this.renderChart(this.currentDay);
            this.updateStatusLabel(this.currentDay);
            this.updateBreakdown(this.currentDay);
            this.updateStayDisplay();

            // Auto-compute si GMB conectado y no hay datos guardados
            if (!config.savedData && config.gmbConnected && config.hasLocationId) {
                setTimeout(function () {
                    OyBusyHours.showStatusMsg(
                        'No hay datos guardados. Haz clic en "Calcular índice desde GMB" para obtener el índice de interés real de este negocio.',
                        'info'
                    );
                }, 500);
            }
        },

        // ── Constructores de datos ──────────────────────────────────────────

        buildDefaultData: function () {
            return {
                hours:            this.buildEmptyHours(),
                day_weights:      { monday:0, tuesday:0, wednesday:0, thursday:0, friday:0, saturday:0, sunday:0 },
                day_scores_raw:   {},
                metric_breakdown: {},
                avg_stay_min:     45,
                avg_stay_max:     90,
                last_computed:    '',
                period:           {},
                metrics_used:     0,
                source:           'api'
            };
        },

        buildEmptyHours: function () {
            var h = {};
            this.DAYS.forEach(function (d) { h[d] = new Array(24).fill(0); });
            return h;
        },

        // ── Binding ─────────────────────────────────────────────────────────

        bindEvents: function () {
            var self = this;

            $(document).on('click', '.oy-busy-day-tab', function () {
                self.switchDay($(this).data('day'));
            });

            $('#oy-busy-compute-btn').on('click', function () {
                self.computeFromAPI();
            });

            $('#oy-busy-save-btn').on('click', function () {
                self.saveData();
            });

            $('#oy-busy-template-sel').on('change', function () {
                var tpl = $(this).val();
                if (tpl) { self.applyTemplate(tpl); }
            });

            $('#oy-busy-edit-toggle').on('change', function () {
                self.toggleEditMode($(this).is(':checked'));
            });

            $('#oy-busy-stay-min, #oy-busy-stay-max').on('input change', function () {
                self.data.avg_stay_min = parseInt($('#oy-busy-stay-min').val(), 10) || 45;
                self.data.avg_stay_max = parseInt($('#oy-busy-stay-max').val(), 10) || 90;
                self.updateStayDisplay();
            });
        },

        // ── Cambio de día ───────────────────────────────────────────────────

        switchDay: function (day) {
            this.currentDay = day;
            $('.oy-busy-day-tab').removeClass('oy-busy-day-tab--active');
            $('.oy-busy-day-tab[data-day="' + day + '"]').addClass('oy-busy-day-tab--active');

            this.renderChart(day);
            this.updateStatusLabel(day);
            this.updateBreakdown(day);

            if (this.editMode) { this.buildSliders(day); }
        },

        // ── Actualizar todas las tabs ────────────────────────────────────────

        updateAllDayTabs: function () {
            var weights = this.data.day_weights || {};
            var vals    = Object.values(weights);
            var max     = vals.length ? Math.max.apply(null, vals) : 1;
            if (max <= 0) { max = 1; }

            this.DAYS.forEach(function (day) {
                var w   = weights[day] || 0;
                var pct = Math.max(3, Math.round((w / max) * 100));
                $('#oy-day-bar-' + day).css('height', pct + '%');
                $('#oy-day-index-' + day).text(w > 0 ? w : '—');
            });
        },

        // ── Gráfico Chart.js ────────────────────────────────────────────────

        renderChart: function (day) {
            var rawHours = (this.data.hours && this.data.hours[day])
                           ? this.data.hours[day]
                           : new Array(24).fill(0);
            var weight   = (this.data.day_weights && typeof this.data.day_weights[day] !== 'undefined')
                           ? (this.data.day_weights[day] / 100)
                           : 0;

            // Aplicar peso del día a la curva horaria
            var values = rawHours.map(function (v) {
                return Math.min(100, Math.round(v * weight));
            });

            // Mostrar 6a.m. (índice 6) → 11p.m. (índice 23)
            var displayValues = values.slice(6, 24);
            var displayLabels = this.HOUR_LABELS.slice(6, 24);

            // Detectar hora actual para marcarla
            var todayDows = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
            var todayDay  = todayDows[new Date().getDay()];
            var curHour   = new Date().getHours();
            var isToday   = (todayDay === day);
            var curBarIdx = (isToday && curHour >= 6 && curHour <= 23) ? (curHour - 6) : -1;

            // Paleta: coral para hora actual, gris con intensidad por valor
            var bgColors = displayValues.map(function (v, i) {
                if (i === curBarIdx) { return 'rgba(199,90,72,0.88)'; }
                if (v >= 70) { return 'rgba(120,120,120,0.85)'; }
                if (v >= 45) { return 'rgba(155,155,155,0.75)'; }
                if (v >= 20) { return 'rgba(185,185,185,0.65)'; }
                return 'rgba(210,210,210,0.35)';
            });

            var ctx = document.getElementById('oy-busy-chart');
            if (!ctx) { return; }
            if (this.chart) { this.chart.destroy(); this.chart = null; }
            if (typeof Chart === 'undefined') {
                console.warn('[OyBusyHours] Chart.js no disponible todavía.');
                return;
            }

            this.chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: displayLabels,
                    datasets: [{
                        data:               displayValues,
                        backgroundColor:    bgColors,
                        borderColor:        bgColors,
                        borderRadius:       4,
                        borderSkipped:      false,
                        barPercentage:      0.80,
                        categoryPercentage: 0.90
                    }]
                },
                options: {
                    responsive:          true,
                    maintainAspectRatio: false,
                    animation:           { duration: 200 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: false,
                            callbacks: {
                                title: function (items) { return items[0].label; },
                                label: function (item) {
                                    var v = item.raw;
                                    if (v === 0)  { return 'Sin actividad / cerrado'; }
                                    if (v <= 15)  { return 'Muy bajo interés'; }
                                    if (v <= 40)  { return 'Bajo interés'; }
                                    if (v <= 65)  { return 'Interés normal'; }
                                    if (v <= 85)  { return 'Alto interés'; }
                                    return 'Máximo interés';
                                }
                            },
                            backgroundColor: 'rgba(20,20,20,0.93)',
                            titleColor:      '#fff',
                            bodyColor:       '#ddd',
                            padding:         10,
                            cornerRadius:    6
                        }
                    },
                    scales: {
                        x: {
                            grid:   { display: false },
                            border: { display: false },
                            ticks:  { color:'#aaa', font:{ size:10 }, maxRotation:0, autoSkip:true, maxTicksLimit:9 }
                        },
                        y: { display:false, min:0, max:100 }
                    }
                }
            });

            // Actualizar badge de score del día
            var w = (this.data.day_weights && typeof this.data.day_weights[day] !== 'undefined')
                    ? this.data.day_weights[day] : 0;
            if (w > 0) {
                $('#oy-busy-score-num').text(w);
                $('#oy-busy-day-score-badge').show();
            } else {
                $('#oy-busy-day-score-badge').hide();
            }
        },

        // ── Etiqueta de interés del día ──────────────────────────────────────

        updateStatusLabel: function (day) {
            var w = (this.data.day_weights && typeof this.data.day_weights[day] !== 'undefined')
                    ? this.data.day_weights[day] : 0;
            var hours = (this.data.hours && this.data.hours[day]) ? this.data.hours[day] : [];
            var hasHours = hours.some(function (v) { return v > 0; });
            var title, subtitle, dotColor;

            if (w === 0 && !hasHours) {
                title    = 'Sin datos — calcule el índice desde GMB';
                subtitle = 'Haga clic en "Calcular índice desde GMB" para obtener el índice real';
                dotColor = '#757575';
            } else if (w >= 85) {
                title    = 'Día de máximo interés';
                subtitle = 'Este es uno de los días de mayor actividad del negocio';
                dotColor = '#e53935';
            } else if (w >= 65) {
                title    = 'Alto interés';
                subtitle = 'Actividad por encima del promedio semanal';
                dotColor = '#f57c00';
            } else if (w >= 40) {
                title    = 'Interés moderado';
                subtitle = 'Actividad en línea con el promedio de la semana';
                dotColor = '#fbc02d';
            } else if (w >= 15) {
                title    = 'Bajo interés';
                subtitle = 'Actividad por debajo del promedio semanal';
                dotColor = '#43a047';
            } else {
                title    = 'Muy bajo interés';
                subtitle = 'Poca actividad registrada este día';
                dotColor = '#757575';
            }

            $('#oy-busy-status-title').text(title);
            $('#oy-busy-status-subtitle').text(subtitle);
            $('#oy-busy-live-dot').css('background', dotColor)
                                  .css('box-shadow', '0 0 6px ' + dotColor + '88');
        },

        // ── Desglose de grupos de métricas ───────────────────────────────────

        updateBreakdown: function (day) {
            var self      = this;
            var breakdown = (this.data.metric_breakdown && this.data.metric_breakdown[day])
                            ? this.data.metric_breakdown[day] : null;

            if (!breakdown) {
                $('#oy-busy-breakdown').hide();
                return;
            }

            var groups    = ['high_intent','visit_intent','research','impressions'];
            var total     = 0;
            groups.forEach(function (g) { total += (breakdown[g] || 0); });
            if (total <= 0) { $('#oy-busy-breakdown').hide(); return; }

            var $bars = $('#oy-busy-breakdown-bars').empty();

            groups.forEach(function (g) {
                var val  = breakdown[g] || 0;
                if (val <= 0) { return; }
                var pct  = Math.round((val / total) * 100);
                var $bar = $(
                    '<div class="oy-busy-bd-item">' +
                        '<span class="oy-busy-bd-dot" style="background:' + self.GROUP_COLORS[g] + ';"></span>' +
                        '<span class="oy-busy-bd-label">' + self.GROUP_LABELS[g] + '</span>' +
                        '<div class="oy-busy-bd-track">' +
                            '<div class="oy-busy-bd-fill" style="width:' + pct + '%;background:' + self.GROUP_COLORS[g] + ';"></div>' +
                        '</div>' +
                        '<span class="oy-busy-bd-pct">' + pct + '%</span>' +
                    '</div>'
                );
                $bars.append($bar);
            });

            $('#oy-busy-breakdown').show();
        },

        // ── Permanencia ─────────────────────────────────────────────────────

        updateStayDisplay: function () {
            var minM = this.data.avg_stay_min || 45;
            var maxM = this.data.avg_stay_max || 90;
            var fmt  = function (m) {
                if (m >= 60) {
                    var h = Math.floor(m / 60), r = m % 60;
                    return r ? (h + ' h ' + r + ' min') : (h + ' h');
                }
                return m + ' min';
            };
            $('#oy-busy-stay-display').text('Entre ' + fmt(minM) + ' y ' + fmt(maxM));
        },

        // ── Aplicar plantilla horaria ────────────────────────────────────────

        applyTemplate: function (tplKey) {
            var tpl = (this.config.templates || {})[tplKey];
            if (!tpl) { return; }
            var self = this;
            this.DAYS.forEach(function (d) {
                self.data.hours[d] = tpl.slice();
            });
            this.renderChart(this.currentDay);
            this.updateStatusLabel(this.currentDay);
            if (this.editMode) { this.buildSliders(this.currentDay); }
            this.showStatusMsg('Plantilla "' + tplKey + '" aplicada a todos los días. Ajusta si lo necesitas y guarda.', 'info');
        },

        // ── Edit mode ───────────────────────────────────────────────────────

        toggleEditMode: function (on) {
            this.editMode = on;
            if (on) {
                $('#oy-busy-edit-area').slideDown(200);
                this.buildSliders(this.currentDay);
            } else {
                $('#oy-busy-edit-area').slideUp(200);
            }
        },

        buildSliders: function (day) {
            var self  = this;
            var hours = (this.data.hours && this.data.hours[day]) ? this.data.hours[day] : new Array(24).fill(0);
            var $grid = $('#oy-busy-sliders-grid').empty();

            for (var h = 6; h <= 23; h++) {
                var val   = hours[h] || 0;
                var $item = $('<div class="oy-busy-slider-item"></div>');
                var $lbl  = $('<span class="oy-busy-slider-label">' + this.HOUR_LABELS[h] + '</span>');
                var $val  = $('<span class="oy-busy-slider-val">' + val + '</span>');
                var $inp  = $('<input type="range" class="oy-busy-slider-range" min="0" max="100" step="5" />')
                            .val(val).attr('data-hour', h);

                $inp.on('input', (function (hour, $vd) {
                    return function () {
                        var v = parseInt($(this).val(), 10);
                        self.data.hours[self.currentDay][hour] = v;
                        $vd.text(v);
                        self.renderChart(self.currentDay);
                        self.updateStatusLabel(self.currentDay);
                    };
                }(h, $val)));

                $item.append($lbl).append($inp).append($val);
                $grid.append($item);
            }
        },

        // ── Compute desde Performance API ───────────────────────────────────

        computeFromAPI: function () {
            var self = this;
            var $btn = $('#oy-busy-compute-btn');

            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-update oy-spin" style="vertical-align:middle;margin-top:3px;margin-right:2px;"></span> Calculando…'
            );
            this.showStatusMsg('Consultando Performance API (90 días, 11 métricas ponderadas)…', 'loading');

            $.ajax({
                url:     this.config.ajaxUrl,
                method:  'POST',
                timeout: 60000,
                data: {
                    action:  'oy_gmb_busy_compute',
                    nonce:   this.config.nonce,
                    post_id: this.config.postId
                },
                success: function (response) {
                    if (response.success) {
                        var d = response.data;

                        // Actualizar datos en memoria
                        self.data.day_weights      = d.day_weights;
                        self.data.day_scores_raw   = d.scores_raw;
                        self.data.metric_breakdown = d.group_breakdown;
                        self.data.last_computed    = d.computed_at;
                        self.data.period           = d.period;
                        self.data.metrics_used     = d.metrics_used;
                        self.data.source           = 'api';

                        // Re-renderizar todo
                        self.updateAllDayTabs();
                        self.renderChart(self.currentDay);
                        self.updateStatusLabel(self.currentDay);
                        self.updateBreakdown(self.currentDay);

                        var msg = '✅ Índice calculado: ' + d.days_processed + ' días procesados, ' +
                                  d.metrics_used + ' métricas activas (' + d.period.start + ' → ' + d.period.end + '). ' +
                                  'Selecciona la forma horaria de tu negocio y guarda.';
                        self.showStatusMsg(msg, 'success');

                        // Marcar encabezado
                        $('.oy-busy-computed-header').remove();
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : 'Error desconocido';
                        self.showStatusMsg('❌ ' + errMsg, 'error');
                    }
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                              ? xhr.responseJSON.data.message : xhr.statusText;
                    self.showStatusMsg('Error de conexión: ' + msg, 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:3px;margin-right:2px;"></span> Calcular índice desde GMB'
                    );
                }
            });
        },

        // ── Guardar ─────────────────────────────────────────────────────────

        saveData: function () {
            var self = this;
            var $btn = $('#oy-busy-save-btn');

            this.data.avg_stay_min = parseInt($('#oy-busy-stay-min').val(), 10) || 45;
            this.data.avg_stay_max = parseInt($('#oy-busy-stay-max').val(), 10) || 90;

            $btn.prop('disabled', true).text('Guardando…');
            this.showStatusMsg('Guardando…', 'loading');

            $.ajax({
                url:     this.config.ajaxUrl,
                method:  'POST',
                timeout: 30000,
                data: {
                    action:    'oy_gmb_busy_save',
                    nonce:     this.config.nonce,
                    post_id:   this.config.postId,
                    peak_data: JSON.stringify(this.data)
                },
                success: function (response) {
                    if (response.success) {
                        self.showStatusMsg('✅ ' + response.data.message, 'success');
                    } else {
                        var m = (response.data && response.data.message) ? response.data.message : '';
                        self.showStatusMsg('Error al guardar: ' + m, 'error');
                    }
                },
                error: function () {
                    self.showStatusMsg('Error de conexión. Intenta de nuevo.', 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-saved" style="vertical-align:middle;margin-top:3px;margin-right:2px;"></span> Guardar'
                    );
                }
            });
        },

        // ── Status helper ────────────────────────────────────────────────────

        showStatusMsg: function (msg, type) {
            var $s = $('#oy-busy-status-msg');
            $s.removeClass('oy-busy-status--loading oy-busy-status--error oy-busy-status--info oy-busy-status--success');
            $s.addClass('oy-busy-status--' + (type || 'info')).html(msg).show();
            if (type === 'success') {
                setTimeout(function () { $s.fadeOut(400); }, 6000);
            }
        }

    }; // end OyBusyHours

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    $(document).ready(function () {
        if (typeof oyBusyConfig !== 'undefined' && document.getElementById('oy-busy-wrap')) {
            var boot = function () { OyBusyHours.init(oyBusyConfig); };
            if (typeof window.waitForChart === 'function') {
                window.waitForChart(boot);
            } else if (typeof Chart !== 'undefined') {
                boot();
            } else {
                setTimeout(boot, 250);
            }
        }
    });

}(jQuery));
JSEOF;
	}

	// ── Inline CSS ────────────────────────────────────────────────────────────

	private function get_inline_css() {
		return '
/* ==========================================================================
   OY Location — Horario de Mayor Interés v2
   ========================================================================== */
#oy-busy-wrap { font-size:13px; color:#1e1e1e; }

.oy-busy-notice {
    padding:10px 14px; border-radius:4px; margin-bottom:10px;
    border:1px solid transparent; display:flex; align-items:center; gap:8px;
}
.oy-busy-notice--warn { background:#fff3cd; border-color:#ffc107; color:#856404; }

/* Computed header */
.oy-busy-computed-header {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
    gap:8px; padding:8px 12px; margin-bottom:10px;
    background:#e8f5e9; border:1px solid #a5d6a7; border-radius:6px;
}
.oy-busy-computed-header__left  { display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
.oy-busy-computed-header__right { display:flex; align-items:center; gap:8px; }
.oy-busy-source-pill {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600;
    background:#2e7d32; color:#fff;
}
.oy-busy-source-pill--api { background:#2e7d32; }
.oy-busy-period-label { font-size:11px; color:#555; }
.oy-busy-peak-badge {
    padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600;
    background:#1a73e8; color:#fff;
}

/* Toolbar */
.oy-busy-toolbar {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
    gap:8px; background:#f6f7f7; border:1px solid #ddd; border-radius:6px;
    padding:10px 14px; margin-bottom:12px;
}
.oy-busy-toolbar__left  { display:flex; align-items:center; flex-wrap:wrap; gap:12px; }
.oy-busy-toolbar__right { display:flex; gap:8px; flex-wrap:wrap; }
.oy-busy-field-group    { display:flex; align-items:center; gap:6px; }
.oy-busy-field-group label { font-weight:600; font-size:12px; white-space:nowrap; }
.oy-busy-select { min-width:170px; }

/* Status messages */
.oy-busy-status {
    padding:9px 14px; border-radius:4px; margin-bottom:10px;
    border:1px solid transparent; font-size:12px; line-height:1.5;
}
.oy-busy-status--loading { background:#e8f4fb; border-color:#bee5eb; color:#0c5460; }
.oy-busy-status--error   { background:#fff3cd; border-color:#ffc107; color:#856404; }
.oy-busy-status--info    { background:#d1ecf1; border-color:#bee5eb; color:#0c5460; }
.oy-busy-status--success { background:#d4edda; border-color:#c3e6cb; color:#155724; }

/* ── Dark panel ── */
.oy-busy-dark-panel {
    background:#2d2d2d; border-radius:8px;
    padding:0 0 16px; margin-bottom:12px; overflow:hidden;
}

/* Day tabs */
.oy-busy-day-tabs {
    display:flex; align-items:flex-end; gap:0;
    background:#252525; padding:8px 8px 0;
}
.oy-busy-day-tab {
    flex:1; display:flex; flex-direction:column; align-items:center;
    gap:3px; padding:6px 4px 8px;
    background:transparent; border:none; cursor:pointer;
    color:#888; font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
    transition:color .15s; position:relative;
}
.oy-busy-day-tab:hover { color:#ccc; }
.oy-busy-day-tab--active { color:#fff; border-bottom:2px solid #fff; margin-bottom:-2px; }

/* Mini bar en cada tab */
.oy-busy-day-bar-track {
    width:24px; height:32px;
    background:rgba(255,255,255,0.07); border-radius:3px 3px 0 0;
    display:flex; align-items:flex-end; overflow:hidden;
}
.oy-busy-day-bar {
    width:100%; background:rgba(160,160,160,0.50);
    border-radius:3px 3px 0 0; transition:height .4s ease; min-height:3px;
}
.oy-busy-day-tab--active .oy-busy-day-bar { background:rgba(255,255,255,0.70); }
.oy-busy-day-name  { font-size:10px; margin-top:2px; }
.oy-busy-day-index { font-size:10px; color:#aaa; margin-top:1px; font-weight:400; }
.oy-busy-day-tab--active .oy-busy-day-index { color:#fff; font-weight:700; }

/* Status row */
.oy-busy-status-row {
    display:flex; align-items:flex-start; gap:10px;
    padding:14px 18px 10px; position:relative;
}
.oy-busy-live-dot {
    width:12px; height:12px; border-radius:50%;
    background:#e53935; margin-top:3px; flex-shrink:0;
    transition:background .3s, box-shadow .3s;
}
.oy-busy-status-title   { font-weight:700; font-size:13px; color:#fff; line-height:1.3; }
.oy-busy-status-subtitle { font-size:11px; color:#aaa; font-style:italic; margin-top:2px; }

/* Score badge */
.oy-busy-day-score-badge {
    margin-left:auto; flex-shrink:0;
    display:flex; flex-direction:column; align-items:center;
    background:rgba(255,255,255,.08); border-radius:8px;
    padding:6px 12px; min-width:54px;
}
.oy-busy-score-num   { font-size:22px; font-weight:800; color:#fff; line-height:1; }
.oy-busy-score-label { font-size:10px; color:#aaa; margin-top:2px; letter-spacing:.04em; }

/* Chart */
.oy-busy-chart-container {
    position:relative; height:160px; width:100%;
    padding:0 12px; box-sizing:border-box;
}
.oy-busy-chart-container canvas {
    display:block !important; width:100% !important; height:100% !important;
}

/* Desglose */
.oy-busy-breakdown {
    padding:10px 18px 6px;
    border-top:1px solid rgba(255,255,255,.07);
    margin-top:8px;
}
.oy-busy-breakdown__label {
    font-size:11px; color:#999; font-weight:600;
    display:block; margin-bottom:8px; letter-spacing:.03em;
}
.oy-busy-breakdown__bars { display:flex; flex-direction:column; gap:5px; }
.oy-busy-bd-item {
    display:flex; align-items:center; gap:7px; font-size:11px;
}
.oy-busy-bd-dot   { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.oy-busy-bd-label { color:#bbb; min-width:130px; white-space:nowrap; }
.oy-busy-bd-track {
    flex:1; height:6px; background:rgba(255,255,255,.1);
    border-radius:3px; overflow:hidden;
}
.oy-busy-bd-fill  { height:100%; border-radius:3px; transition:width .4s ease; }
.oy-busy-bd-pct   { color:#888; min-width:32px; text-align:right; font-weight:600; }

/* Stay row */
.oy-busy-stay-row {
    display:flex; align-items:center; gap:4px;
    font-size:12px; color:#ccc;
    border-top:1px solid rgba(255,255,255,.07);
    padding:10px 18px 0; margin-top:6px;
}
.oy-busy-stay-row strong { color:#fff; }

/* Edit header */
.oy-busy-edit-header {
    display:flex; align-items:center; gap:4px; flex-wrap:wrap;
    padding:8px 12px; background:#f0f4ff;
    border:1px solid #c8d8ff; border-radius:6px; margin-bottom:8px;
}
.oy-busy-toggle-wrap {
    display:flex; align-items:center; gap:6px;
    cursor:pointer; font-weight:600; font-size:12px; color:#3d4d7a;
}
.oy-busy-toggle-label { display:flex; align-items:center; gap:4px; }

/* Sliders */
.oy-busy-sliders-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(72px,1fr));
    gap:8px; background:#fff; border:1px solid #ddd;
    border-radius:6px; padding:12px; margin-bottom:4px;
}
.oy-busy-slider-item  { display:flex; flex-direction:column; align-items:center; gap:4px; }
.oy-busy-slider-label { font-size:10px; font-weight:700; color:#555; text-transform:uppercase; letter-spacing:.03em; }
.oy-busy-slider-range {
    -webkit-appearance:slider-vertical; writing-mode:vertical-lr;
    direction:rtl; height:70px; accent-color:#1a73e8; width:100%;
}
@media (max-width:782px) {
    .oy-busy-slider-range { writing-mode:horizontal-tb; direction:ltr; -webkit-appearance:auto; height:auto; }
}
.oy-busy-slider-val { font-size:11px; font-weight:700; color:#1a73e8; min-width:26px; text-align:center; }

/* Spin animation para botón computing */
@keyframes oy-spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
.oy-spin { display:inline-block; animation:oy-spin .8s linear infinite; }

/* Footer */
.oy-busy-footer {
    font-size:11px; color:#888; padding:8px 2px 4px;
    border-top:1px solid #eee; margin-top:8px; line-height:1.6;
}
';
	}

}
// end class OY_Location_GMB_BusyHours_Metabox

endif; // class_exists OY_Location_GMB_BusyHours_Metabox
