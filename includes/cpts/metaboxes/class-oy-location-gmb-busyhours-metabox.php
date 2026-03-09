<?php
/**
 * Metabox: Horario de Mayor Concurrencia (Popular Times)
 *
 * Panel para visualizar y configurar los horarios de mayor actividad del negocio.
 * Replica la interfaz de "Popular Times" de Google Maps.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ANÁLISIS DE FUENTES DE DATOS DISPONIBLES EN GMB API
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ✅ LO QUE SÍ HAY — Business Profile Performance API (oficial):
 *    Endpoint: /v1/locations/{id}:fetchMultiDailyMetricsTimeSeries
 *    Métricas disponibles: CALL_CLICKS, WEBSITE_CLICKS, BUSINESS_DIRECTION_REQUESTS,
 *    BUSINESS_IMPRESSIONS_*, BUSINESS_CONVERSATIONS, BUSINESS_BOOKINGS, etc.
 *    Granularidad: DIARIA. Se pueden agregar por día de la semana para obtener
 *    el "peso relativo" de cada día (cuánta interacción genera Lunes vs Domingo).
 *    → Este metabox usa 90 días de datos para calcular esos pesos.
 *
 * ❌ LO QUE NO HAY — Distribución horaria (horas pico):
 *    Los "Popular Times" (histograma de concurrencia por hora) que muestra Google Maps
 *    provienen del historial de ubicación anónimo de usuarios de Google y son
 *    EXCLUSIVOS de la UI de Google Maps. NO están expuestos en ninguna API oficial
 *    de Google Business Profile (ni v1, ni v4, ni Performance API).
 *    → Este metabox gestiona esa data manualmente, con plantillas predefinidas
 *      que el admin ajusta hora por hora mediante sliders interactivos.
 *
 * META GUARDADA: gmb_peak_hours (JSON)
 * ─────────────────────────────────────────────────────────────────────────
 * {
 *   "hours": {
 *     "monday":    [0,0,…,65,80,70,…,0],   // 24 valores, índice = hora (0-23)
 *     "tuesday":   […],
 *     …
 *     "sunday":    […]
 *   },
 *   "day_weights": {           // computado desde Performance API (0-100)
 *     "monday": 72,
 *     "tuesday": 68,
 *     …
 *   },
 *   "avg_stay_min": 45,
 *   "avg_stay_max": 90,
 *   "last_computed": "2026-03-09T10:00:00Z",
 *   "source": "manual|hybrid"
 * }
 *
 * META ADICIONAL GUARDADA:
 *   gmb_busiest_day_of_week  (string: "friday")
 *   gmb_busiest_hour_of_day  (int: 18)
 *   gmb_peak_hours           (JSON completo, ver arriba)
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
 * Registra, renderiza y gestiona el metabox de horario de concurrencia.
 * AJAX handlers:
 *  - wp_ajax_oy_gmb_busy_compute  → Agrega Performance API por día de semana
 *  - wp_ajax_oy_gmb_busy_save     → Guarda gmb_peak_hours en post meta
 */
class OY_Location_GMB_BusyHours_Metabox {

	/**
	 * Nonce action para AJAX.
	 */
	const NONCE_ACTION = 'oy_gmb_busyhours_nonce';

	/**
	 * Meta key donde se guarda el JSON de horas pico.
	 * Sin guión bajo — convención oy_location.
	 */
	const META_KEY = 'gmb_peak_hours';

	/**
	 * Plantillas de distribución horaria predefinidas (24 valores, índice = hora 0-23).
	 * Valores 0-100: 0 = cerrado/sin actividad, 100 = máximo pico.
	 *
	 * @var array
	 */
	private static $templates = array(
		'comercio'    => array( 0, 0, 0, 0, 0, 0,  5, 20, 45, 65, 75, 70, 55, 50, 60, 70, 65, 55, 40, 25, 10,  5,  0, 0 ),
		'restaurante' => array( 0, 0, 0, 0, 0, 0,  5, 10, 25, 35, 55, 75, 80, 65, 40, 50, 65, 75, 85, 75, 50, 25,  5, 0 ),
		'cafe'        => array( 0, 0, 0, 0, 0, 0, 25, 65, 80, 75, 65, 55, 45, 35, 25, 20, 15, 10,  5,  0,  0,  0,  0, 0 ),
		'nocturno'    => array( 0, 0, 0, 0, 0, 0,  0,  0,  5, 10, 15, 20, 25, 25, 30, 35, 45, 60, 75, 85, 90, 80, 60, 30 ),
		'continuo'    => array( 0, 0, 0, 0, 0, 0,  5, 20, 45, 55, 55, 55, 55, 55, 55, 55, 50, 45, 35, 20, 10,  5,  0, 0 ),
	);

	// ── Constructor / Hooks ──────────────────────────────────────────────────

	public function __construct() {
		add_action( 'add_meta_boxes',        array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_oy_gmb_busy_compute', array( $this, 'ajax_compute_day_weights' ) );
		add_action( 'wp_ajax_oy_gmb_busy_save',    array( $this, 'ajax_save_peak_hours' ) );
	}

	// ── Registration ─────────────────────────────────────────────────────────

	public function register_meta_box() {
		add_meta_box(
			'oy_location_gmb_busyhours',
			__( '🕐 Horario de Mayor Concurrencia', 'lealez' ),
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

		// ── Chart.js 4.4.3 — LOCAL (mismo patrón que OY_Location_GMB_Performance_Metabox) ──
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

		// ── Script propio (inline — sin archivo externo adicional) ─────────────
		// Usamos `false` como src para crear un handle virtual sin archivo.
		// Los datos y la lógica se adjuntan via wp_localize_script / wp_add_inline_script.
		wp_register_script( 'oy-busyhours', false, array( 'jquery', 'chartjs-v4' ), '1.0.0', true );
		wp_enqueue_script( 'oy-busyhours' );

		// ── Datos para el script ────────────────────────────────────────────
		$saved_raw  = get_post_meta( $post->ID, self::META_KEY, true );
		$saved_data = ( ! empty( $saved_raw ) ) ? json_decode( $saved_raw, true ) : null;

		wp_localize_script(
			'oy-busyhours',
			'oyBusyConfig',
			array(
				'postId'    => $post->ID,
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'savedData' => $saved_data,
				'templates' => self::$templates,
			)
		);

		// El inline JS se adjunta DESPUÉS del localize para que oyBusyConfig esté disponible.
		wp_add_inline_script( 'oy-busyhours', $this->get_inline_js() );

		// CSS inline
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

		?>
		<div id="oy-busy-wrap">

			<?php if ( ! $gmb_connected ) : ?>
			<div class="oy-busy-notice oy-busy-notice--warn">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'El negocio padre no tiene Google Business Profile conectado. Los pesos por día no se podrán computar desde la API, pero puedes ingresar los datos manualmente.', 'lealez' ); ?>
			</div>
			<?php elseif ( ! $location_id ) : ?>
			<div class="oy-busy-notice oy-busy-notice--warn">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Esta ubicación no está vinculada a una propiedad de Google Business Profile. Configura el GMB Location ID primero.', 'lealez' ); ?>
			</div>
			<?php endif; ?>

			<!-- ── Toolbar ── -->
			<div class="oy-busy-toolbar">
				<div class="oy-busy-toolbar__left">

					<div class="oy-busy-field-group">
						<label for="oy-busy-template-sel"><?php esc_html_e( 'Plantilla horaria:', 'lealez' ); ?></label>
						<select id="oy-busy-template-sel" class="oy-busy-select">
							<option value=""><?php esc_html_e( '— Seleccionar —', 'lealez' ); ?></option>
							<option value="comercio"><?php esc_html_e( 'Comercio / Tienda', 'lealez' ); ?></option>
							<option value="restaurante"><?php esc_html_e( 'Restaurante', 'lealez' ); ?></option>
							<option value="cafe"><?php esc_html_e( 'Café / Desayunos', 'lealez' ); ?></option>
							<option value="nocturno"><?php esc_html_e( 'Nocturno / Bar', 'lealez' ); ?></option>
							<option value="continuo"><?php esc_html_e( 'Horario continuo', 'lealez' ); ?></option>
						</select>
					</div>

					<div class="oy-busy-field-group">
						<label><?php esc_html_e( 'Permanencia:', 'lealez' ); ?></label>
						<input type="number" id="oy-busy-stay-min" min="5" max="480" step="5" value="45" class="small-text" style="width:60px;">
						<span>–</span>
						<input type="number" id="oy-busy-stay-max" min="5" max="480" step="5" value="90" class="small-text" style="width:60px;">
						<span class="description"><?php esc_html_e( 'min', 'lealez' ); ?></span>
					</div>

				</div>
				<div class="oy-busy-toolbar__right">
					<?php if ( $gmb_connected && $location_id ) : ?>
					<button type="button" id="oy-busy-compute-btn" class="button button-secondary">
						<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:3px;margin-right:2px;"></span>
						<?php esc_html_e( 'Cargar pesos desde GMB', 'lealez' ); ?>
					</button>
					<?php endif; ?>
					<button type="button" id="oy-busy-save-btn" class="button button-primary">
						<span class="dashicons dashicons-saved" style="vertical-align:middle;margin-top:3px;margin-right:2px;"></span>
						<?php esc_html_e( 'Guardar', 'lealez' ); ?>
					</button>
				</div>
			</div>

			<!-- Status line -->
			<div id="oy-busy-status-msg" class="oy-busy-status" style="display:none;"></div>

			<!-- ── Day tabs + mini weight bars ── -->
			<div class="oy-busy-days-panel">
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
						data-day="<?php echo esc_attr( $key ); ?>"
						title="<?php echo esc_attr( ucfirst( $key ) ); ?>">
						<span class="oy-busy-day-mini-bar-track">
							<span class="oy-busy-day-mini-bar" id="oy-day-bar-<?php echo esc_attr( $key ); ?>" style="height:15%;"></span>
						</span>
						<span class="oy-busy-day-label"><?php echo esc_html( $label ); ?></span>
					</button>
					<?php $first = false; endforeach; ?>
				</div>
			</div>

			<!-- ── Chart panel — dark themed, similar a GMB UI ── -->
			<div class="oy-busy-chart-panel">

				<div class="oy-busy-status-row">
					<span class="oy-busy-live-dot" id="oy-busy-live-dot"></span>
					<div>
						<div class="oy-busy-status-title" id="oy-busy-chart-status-title">
							<?php esc_html_e( 'Sin datos cargados', 'lealez' ); ?>
						</div>
						<div class="oy-busy-status-subtitle" id="oy-busy-chart-status-subtitle">
							<?php esc_html_e( 'Usa una plantilla o carga los pesos desde GMB y ajusta manualmente', 'lealez' ); ?>
						</div>
					</div>
				</div>

				<div class="oy-busy-chart-container">
					<canvas id="oy-busy-chart"></canvas>
				</div>

				<div class="oy-busy-stay-row">
					<span class="dashicons dashicons-clock" style="margin-right:5px;opacity:.8;"></span>
					<span><?php esc_html_e( 'Promedio de permanencia:', 'lealez' ); ?></span>
					<strong id="oy-busy-stay-display" style="margin-left:5px;">—</strong>
				</div>

				<div class="oy-busy-source-row" id="oy-busy-source-row" style="display:none;">
					<span class="oy-busy-source-badge" id="oy-busy-source-badge"></span>
					<span class="oy-busy-computed-at" id="oy-busy-computed-at"></span>
				</div>

			</div><!-- .oy-busy-chart-panel -->

			<!-- ── Edit mode toggle ── -->
			<div class="oy-busy-edit-header">
				<label class="oy-busy-toggle-wrap">
					<input type="checkbox" id="oy-busy-edit-toggle">
					<span class="oy-busy-toggle-label">
						<span class="dashicons dashicons-edit" style="font-size:14px;vertical-align:middle;margin-right:3px;"></span>
						<?php esc_html_e( 'Editar distribución horaria manualmente', 'lealez' ); ?>
					</span>
				</label>
				<span style="font-size:11px;color:#999;margin-left:10px;">
					<?php esc_html_e( 'Ajusta los valores hora a hora para el día seleccionado (6 a.m. – 11 p.m.)', 'lealez' ); ?>
				</span>
			</div>

			<!-- Sliders area (oculta por defecto) -->
			<div id="oy-busy-edit-area" style="display:none;">
				<div class="oy-busy-sliders-grid" id="oy-busy-sliders-grid">
					<!-- Construido por JS al activar edit mode -->
				</div>
				<p style="font-size:11px;color:#888;margin:6px 0 0;">
					<?php esc_html_e( 'Los cambios se reflejan en el gráfico en tiempo real. Recuerda guardar cuando termines.', 'lealez' ); ?>
				</p>
			</div>

			<!-- ── Footer técnico ── -->
			<div class="oy-busy-footer">
				<span class="dashicons dashicons-info" style="font-size:13px;vertical-align:middle;margin-right:3px;opacity:.7;"></span>
				<?php
				esc_html_e(
					'Nota técnica: Google no expone los "Popular Times" (distribución por hora) en ninguna API oficial de Business Profile. El botón "Cargar pesos desde GMB" agrega CALL_CLICKS + WEBSITE_CLICKS + BUSINESS_DIRECTION_REQUESTS de los últimos 90 días desde la Performance API para calcular el peso relativo de cada día de la semana. La distribución horaria dentro de cada día se gestiona manualmente desde este panel.',
					'lealez'
				);
				?>
			</div>

		</div><!-- #oy-busy-wrap -->
		<?php
	}

	// ── AJAX: Compute day weights from Performance API ───────────────────────

	/**
	 * Calcula el peso relativo de cada día de la semana usando datos reales de la
	 * Business Profile Performance API.
	 *
	 * Proceso:
	 *  1. Obtiene 90 días de CALL_CLICKS + WEBSITE_CLICKS + BUSINESS_DIRECTION_REQUESTS.
	 *  2. Agrupa por día de la semana (0=Sun … 6=Sat).
	 *  3. Calcula el promedio diario por day-of-week.
	 *  4. Normaliza a 0-100 (el día con más actividad = 100).
	 *
	 * Action: oy_gmb_busy_compute
	 */
	public function ajax_compute_day_weights() {
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
			wp_send_json_error( array( 'message' => __( 'Faltan datos de configuración GMB (gmb_location_id o parent_business_id).', 'lealez' ) ) );
		}

		if ( ! class_exists( 'Lealez_GMB_API' ) ) {
			wp_send_json_error( array( 'message' => __( 'Clase Lealez_GMB_API no encontrada.', 'lealez' ) ) );
		}

		// ── Rango: últimos 90 días (hasta ayer) ──────────────────────────────
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

		$metrics = array( 'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS' );

		$result = Lealez_GMB_API::get_location_performance_metrics(
			$business_id,
			$location_name,
			$metrics,
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

		// ── Mapeo de índice gmdate('w') → day key ────────────────────────────
		// gmdate('w') retorna 0=Sunday … 6=Saturday
		$dow_map = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );

		$sums   = array_fill_keys( $dow_map, 0 );
		$counts = array_fill_keys( $dow_map, 0 );

		// ── Parsear multiDailyMetricTimeSeries (misma estructura que OY_Location_GMB_Performance_Metabox) ──
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

				$metric_key = isset( $item['dailyMetric'] ) ? (string) $item['dailyMetric'] : '';
				if ( ! in_array( $metric_key, $metrics, true ) ) {
					continue;
				}

				$dated_values = isset( $item['timeSeries']['datedValues'] )
					? (array) $item['timeSeries']['datedValues']
					: array();

				foreach ( $dated_values as $dv ) {
					if ( ! is_array( $dv ) || ! isset( $dv['date'] ) ) {
						continue;
					}

					$d = $dv['date'];
					$date_str = sprintf(
						'%04d-%02d-%02d',
						isset( $d['year'] )  ? (int) $d['year']  : 0,
						isset( $d['month'] ) ? (int) $d['month'] : 0,
						isset( $d['day'] )   ? (int) $d['day']   : 0
					);

					$ts      = strtotime( $date_str );
					$dow_idx = (int) gmdate( 'w', $ts ); // 0-6
					$day_key = $dow_map[ $dow_idx ];

					// API retorna value como string; ausencia = 0 (sin actividad ese día)
					$val = 0;
					if ( array_key_exists( 'value', $dv ) && null !== $dv['value'] ) {
						$val = (int) $dv['value'];
					}

					$sums[ $day_key ]   += $val;
					$counts[ $day_key ] += 1;
				}
			}
		}

		// ── Calcular promedios ────────────────────────────────────────────────
		$averages = array();
		foreach ( $dow_map as $dk ) {
			$cnt            = max( 1, $counts[ $dk ] );
			$averages[ $dk ] = round( $sums[ $dk ] / $cnt, 3 );
		}

		// ── Normalizar a 0-100 ────────────────────────────────────────────────
		$max_avg = max( $averages );
		if ( $max_avg <= 0 ) {
			wp_send_json_error( array(
				'message' => __( 'No se encontraron datos de actividad para el período consultado. Verifica el scope OAuth businessprofileperformance.readonly.', 'lealez' ),
			) );
		}

		$weights = array();
		foreach ( $averages as $dk => $avg ) {
			$weights[ $dk ] = (int) round( $avg / $max_avg * 100 );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[OyBusyHours] Day weights: ' . wp_json_encode( $weights, JSON_UNESCAPED_UNICODE ) );
			error_log( '[OyBusyHours] Sums: ' . wp_json_encode( $sums, JSON_UNESCAPED_UNICODE ) );
		}

		wp_send_json_success( array(
			'day_weights' => $weights,
			'averages'    => $averages,
			'sums'        => $sums,
			'period'      => array(
				'start' => sprintf( '%04d-%02d-%02d', $start_date['year'], $start_date['month'], $start_date['day'] ),
				'end'   => sprintf( '%04d-%02d-%02d', $end_date['year'], $end_date['month'], $end_date['day'] ),
			),
			'computed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		) );
	}

	// ── AJAX: Save peak hours to post meta ───────────────────────────────────

	/**
	 * Guarda el JSON de horas pico en el meta del post.
	 * También actualiza gmb_busiest_day_of_week y gmb_busiest_hour_of_day.
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

		// ── Sanitizar pesos por día ───────────────────────────────────────────
		$clean_weights = array();
		$weights_input = isset( $peak_data['day_weights'] ) ? (array) $peak_data['day_weights'] : array();
		foreach ( $day_keys as $dk ) {
			$clean_weights[ $dk ] = isset( $weights_input[ $dk ] )
				? max( 0, min( 100, (int) $weights_input[ $dk ] ) )
				: 0;
		}

		// ── Construir payload limpio ──────────────────────────────────────────
		$clean_data = array(
			'hours'         => $clean_hours,
			'day_weights'   => $clean_weights,
			'avg_stay_min'  => max( 5, min( 480, (int) ( $peak_data['avg_stay_min'] ?? 45 ) ) ),
			'avg_stay_max'  => max( 5, min( 480, (int) ( $peak_data['avg_stay_max'] ?? 90 ) ) ),
			'last_computed' => sanitize_text_field( $peak_data['last_computed'] ?? '' ),
			'source'        => in_array( $peak_data['source'] ?? 'manual', array( 'manual', 'hybrid', 'computed' ), true )
				? $peak_data['source']
				: 'manual',
		);

		// wp_json_encode con flags para evitar corrupción de caracteres especiales
		$json_to_save = wp_json_encode( $clean_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		update_post_meta( $post_id, self::META_KEY, $json_to_save );

		// ── Actualizar meta fields individuales ───────────────────────────────
		$busiest_day  = '';
		$max_weight   = -1;
		foreach ( $clean_data['day_weights'] as $dk => $w ) {
			if ( $w > $max_weight ) {
				$max_weight  = $w;
				$busiest_day = $dk;
			}
		}

		if ( $busiest_day ) {
			update_post_meta( $post_id, 'gmb_busiest_day_of_week', $busiest_day );

			// Hora pico dentro del día más activo
			$day_hours    = $clean_data['hours'][ $busiest_day ] ?? array();
			$max_val      = -1;
			$busiest_hour = 0;
			foreach ( $day_hours as $h => $v ) {
				if ( $v > $max_val ) {
					$max_val      = $v;
					$busiest_hour = $h;
				}
			}
			update_post_meta( $post_id, 'gmb_busiest_hour_of_day', $busiest_hour );
		}

		wp_send_json_success( array(
			'message' => __( 'Horarios de concurrencia guardados correctamente.', 'lealez' ),
		) );
	}

	// ── Inline JavaScript ────────────────────────────────────────────────────

	private function get_inline_js() {
		// Nowdoc — no interpolación PHP
		return <<<'JSEOF'
(function ($) {
    'use strict';

    /* =====================================================================
     * OyBusyHours — controlador del metabox de horario de concurrencia
     * ===================================================================== */
    var OyBusyHours = {

        config:     null,    // Datos inyectados desde PHP (oyBusyConfig)
        chart:      null,    // Instancia de Chart.js
        currentDay: 'monday',
        data:       null,    // Objeto de datos completo
        editMode:   false,

        // Orden de días para iteración
        DAYS: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],

        // Etiquetas de horas (0-23), formato short como en GMB
        HOUR_LABELS: [
            '12a', '1a',  '2a',  '3a',  '4a',  '5a',
            '6a',  '7a',  '8a',  '9a',  '10a', '11a',
            '12p', '1p',  '2p',  '3p',  '4p',  '5p',
            '6p',  '7p',  '8p',  '9p',  '10p', '11p'
        ],

        // ── Inicialización ──────────────────────────────────────────────────

        init: function (config) {
            this.config = config;

            if (config.savedData && config.savedData.hours) {
                this.data = config.savedData;
                if (!this.data.day_weights)  this.data.day_weights  = this.defaultWeights();
                if (!this.data.avg_stay_min) this.data.avg_stay_min = 45;
                if (!this.data.avg_stay_max) this.data.avg_stay_max = 90;
                if (!this.data.source)       this.data.source       = 'manual';
            } else {
                this.data = this.buildDefaultData();
            }

            // Restaurar inputs de stay
            $('#oy-busy-stay-min').val(this.data.avg_stay_min || 45);
            $('#oy-busy-stay-max').val(this.data.avg_stay_max || 90);

            this.bindEvents();
            this.renderDayWeightBars();
            this.renderChart(this.currentDay);
            this.updateBusynessLabel(this.currentDay);
            this.updateStayDisplay();
            this.updateSourceBadge();
        },

        // ── Constructores de datos por defecto ──────────────────────────────

        buildDefaultData: function () {
            var hours = {};
            this.DAYS.forEach(function (d) {
                hours[d] = new Array(24).fill(0);
            });
            return {
                hours:        hours,
                day_weights:  this.defaultWeights(),
                avg_stay_min: 45,
                avg_stay_max: 90,
                source:       'manual',
                last_computed: ''
            };
        },

        defaultWeights: function () {
            return {
                monday: 70, tuesday: 65, wednesday: 70,
                thursday: 75, friday: 90, saturday: 80, sunday: 40
            };
        },

        // ── Binding de eventos ──────────────────────────────────────────────

        bindEvents: function () {
            var self = this;

            // Tabs de días
            $(document).on('click', '.oy-busy-day-tab', function () {
                self.switchDay($(this).data('day'));
            });

            // Cargar desde GMB
            $('#oy-busy-compute-btn').on('click', function () {
                self.computeFromAPI();
            });

            // Guardar
            $('#oy-busy-save-btn').on('click', function () {
                self.saveData();
            });

            // Plantilla
            $('#oy-busy-template-sel').on('change', function () {
                var tpl = $(this).val();
                if (tpl) { self.applyTemplate(tpl); }
            });

            // Edit toggle
            $('#oy-busy-edit-toggle').on('change', function () {
                self.toggleEditMode($(this).is(':checked'));
            });

            // Stay inputs
            $('#oy-busy-stay-min, #oy-busy-stay-max').on('input change', function () {
                self.data.avg_stay_min = parseInt($('#oy-busy-stay-min').val()) || 45;
                self.data.avg_stay_max = parseInt($('#oy-busy-stay-max').val()) || 90;
                self.updateStayDisplay();
            });
        },

        // ── Cambio de día activo ────────────────────────────────────────────

        switchDay: function (day) {
            this.currentDay = day;

            $('.oy-busy-day-tab').removeClass('oy-busy-day-tab--active');
            $('.oy-busy-day-tab[data-day="' + day + '"]').addClass('oy-busy-day-tab--active');

            this.renderChart(day);
            this.updateBusynessLabel(day);

            if (this.editMode) {
                this.buildSliders(day);
            }
        },

        // ── Renderizar gráfico Chart.js ─────────────────────────────────────

        renderChart: function (day) {
            var self     = this;
            var rawHours = this.data.hours[day] || new Array(24).fill(0);
            var weight   = (this.data.day_weights && typeof this.data.day_weights[day] !== 'undefined')
                           ? (this.data.day_weights[day] / 100)
                           : 1;

            // Aplicar peso del día para escalar las barras
            var values = rawHours.map(function (v) {
                return Math.min(100, Math.round(v * weight));
            });

            // Mostrar de 6 a.m. (índice 6) hasta 11 p.m. (índice 23) = 18 horas
            var displayValues = values.slice(6, 24);
            var displayLabels = this.HOUR_LABELS.slice(6, 24);

            // Detectar si hoy es el día seleccionado para marcar hora actual
            var now       = new Date();
            var todayDows = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
            var todayDay  = todayDows[now.getDay()];
            var curHour   = now.getHours();
            var isToday   = (todayDay === day);
            // Índice en el array recortado (posición 0 = 6a.m.)
            var curBarIdx = (isToday && curHour >= 6 && curHour <= 23) ? (curHour - 6) : -1;

            // Colores: coral para hora actual, grises para el resto
            // Intensidad del gris varía con el valor (más alto = más oscuro)
            var bgColors = displayValues.map(function (v, i) {
                if (i === curBarIdx) {
                    return 'rgba(199, 90, 72, 0.85)';  // coral, hora actual
                }
                if (v >= 70) { return 'rgba(120,120,120,0.80)'; }
                if (v >= 40) { return 'rgba(155,155,155,0.70)'; }
                if (v >= 10) { return 'rgba(185,185,185,0.60)'; }
                return 'rgba(210,210,210,0.40)';  // casi vacío
            });

            var ctx = document.getElementById('oy-busy-chart');
            if (!ctx) { return; }

            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }

            // Guard: Chart.js debe estar disponible
            if (typeof Chart === 'undefined') {
                console.warn('[OyBusyHours] Chart.js no está disponible todavía.');
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
                    animation:           { duration: 250 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: false,
                            callbacks: {
                                title: function (items) {
                                    return items[0].label;
                                },
                                label: function (item) {
                                    var v = item.raw;
                                    if (v === 0)   { return 'Sin datos / cerrado'; }
                                    if (v <= 20)   { return 'Muy poco concurrido'; }
                                    if (v <= 45)   { return 'Poco concurrido'; }
                                    if (v <= 65)   { return 'Concurrencia normal'; }
                                    if (v <= 82)   { return 'Concurrido'; }
                                    return 'Más concurrido de lo habitual';
                                }
                            },
                            backgroundColor: 'rgba(30,30,30,0.92)',
                            titleColor:      '#ffffff',
                            bodyColor:       '#dddddd',
                            padding:         10,
                            cornerRadius:    6
                        }
                    },
                    scales: {
                        x: {
                            grid:   { display: false },
                            border: { display: false },
                            ticks: {
                                color:        '#aaaaaa',
                                font:         { size: 10 },
                                maxRotation:  0,
                                autoSkip:     true,
                                maxTicksLimit: 9
                            }
                        },
                        y: {
                            display: false,
                            min:     0,
                            max:     100
                        }
                    }
                }
            });
        },

        // ── Etiqueta de concurrencia ────────────────────────────────────────

        updateBusynessLabel: function (day) {
            var hours  = this.data.hours[day] || new Array(24).fill(0);
            var total  = hours.reduce(function (a, b) { return a + b; }, 0);
            var maxVal = Math.max.apply(null, hours);
            var title, subtitle;

            if (total === 0) {
                title    = 'Sin datos cargados';
                subtitle = 'Usa una plantilla o ajusta manualmente';
            } else if (maxVal >= 80) {
                title    = 'Más concurrido de lo habitual';
                subtitle = 'Los tiempos de espera pueden ser mayores';
            } else if (maxVal >= 55) {
                title    = 'Concurrencia normal';
                subtitle = 'En general, no hay que esperar mucho';
            } else if (maxVal >= 25) {
                title    = 'Poco concurrido';
                subtitle = 'En general, no hay que esperar';
            } else {
                title    = 'Muy poco concurrido';
                subtitle = 'Sin tiempos de espera';
            }

            $('#oy-busy-chart-status-title').text(title);
            $('#oy-busy-chart-status-subtitle').text(subtitle);

            // Dot color según intensidad
            var dotColor = maxVal >= 70 ? '#e53935' : (maxVal >= 35 ? '#fb8c00' : '#43a047');
            $('#oy-busy-live-dot').css('background', dotColor);
        },

        // ── Mini barras de peso por día en los tabs ─────────────────────────

        renderDayWeightBars: function () {
            var weights = this.data.day_weights || {};
            var vals    = Object.values(weights);
            var max     = vals.length ? Math.max.apply(null, vals) : 1;
            if (max <= 0) { max = 1; }

            this.DAYS.forEach(function (day) {
                var w   = weights[day] || 0;
                var pct = Math.round((w / max) * 100);
                $('#oy-day-bar-' + day).css('height', Math.max(4, pct) + '%');
            });
        },

        // ── Permanencia promedio ────────────────────────────────────────────

        updateStayDisplay: function () {
            var minM = this.data.avg_stay_min || 45;
            var maxM = this.data.avg_stay_max || 90;

            var fmt = function (m) {
                if (m >= 60) {
                    var h   = Math.floor(m / 60);
                    var rem = m % 60;
                    return rem ? (h + ' h ' + rem + ' min') : (h + ' h');
                }
                return m + ' min';
            };

            $('#oy-busy-stay-display').text('Entre ' + fmt(minM) + ' y ' + fmt(maxM));
        },

        // ── Badge de fuente de datos ────────────────────────────────────────

        updateSourceBadge: function () {
            var src        = this.data.source || 'manual';
            var computedAt = this.data.last_computed || '';
            var $badge     = $('#oy-busy-source-badge');

            if (src === 'manual') {
                $badge.text('Datos manuales').css({ background: '#e8f0fe', color: '#1a73e8' });
            } else if (src === 'computed') {
                $badge.text('Computado desde GMB').css({ background: '#e6f4ea', color: '#137333' });
            } else {
                $badge.text('Híbrido (GMB + manual)').css({ background: '#fce8b2', color: '#b06000' });
            }

            if (computedAt) {
                try {
                    var d = new Date(computedAt);
                    if (!isNaN(d.getTime())) {
                        $('#oy-busy-computed-at').text(
                            'Última computación: ' + d.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' })
                        );
                    } else {
                        $('#oy-busy-computed-at').text('Última computación: ' + computedAt);
                    }
                } catch (e) {
                    $('#oy-busy-computed-at').text('');
                }
                $('#oy-busy-source-row').show();
            }
        },

        // ── Aplicar plantilla ───────────────────────────────────────────────

        applyTemplate: function (tplKey) {
            var templates = this.config.templates || {};
            var tpl       = templates[tplKey];
            if (!tpl) { return; }

            var self = this;
            this.DAYS.forEach(function (day) {
                self.data.hours[day] = tpl.slice(); // copia del array template
            });

            this.data.source = 'manual';
            this.renderChart(this.currentDay);
            this.updateBusynessLabel(this.currentDay);

            if (this.editMode) {
                this.buildSliders(this.currentDay);
            }

            this.showStatusMsg('Plantilla aplicada a todos los días. Ajusta si lo necesitas y guarda.', 'info');
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
            var hours = this.data.hours[day] || new Array(24).fill(0);
            var $grid = $('#oy-busy-sliders-grid').empty();

            // Horas 6 a.m. (6) a 11 p.m. (23)
            for (var h = 6; h <= 23; h++) {
                var val   = hours[h] || 0;
                var label = self.HOUR_LABELS[h];

                var $item = $('<div class="oy-busy-slider-item"></div>');
                var $lbl  = $('<span class="oy-busy-slider-label">' + label + '</span>');
                var $val  = $('<span class="oy-busy-slider-val">' + val + '</span>');
                var $inp  = $('<input type="range" class="oy-busy-slider-range" min="0" max="100" step="5" />')
                            .val(val)
                            .attr('data-hour', h);

                // Closure para capturar h y $val correctamente
                $inp.on('input', (function (hour, $valDisplay) {
                    return function () {
                        var v = parseInt($(this).val(), 10);
                        self.data.hours[self.currentDay][hour] = v;
                        $valDisplay.text(v);
                        self.renderChart(self.currentDay);
                        self.updateBusynessLabel(self.currentDay);
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

            $btn.prop('disabled', true).text('Cargando…');
            this.showStatusMsg('Consultando Performance API (últimos 90 días)…', 'loading');

            $.ajax({
                url:     this.config.ajaxUrl,
                method:  'POST',
                timeout: 45000,
                data: {
                    action:  'oy_gmb_busy_compute',
                    nonce:   this.config.nonce,
                    post_id: this.config.postId
                },
                success: function (response) {
                    if (response.success) {
                        var d = response.data;
                        self.data.day_weights   = d.day_weights;
                        self.data.source        = 'hybrid';
                        self.data.last_computed = d.computed_at;

                        self.renderDayWeightBars();
                        self.renderChart(self.currentDay);
                        self.updateBusynessLabel(self.currentDay);
                        self.updateSourceBadge();
                        self.showStatusMsg(
                            '✅ Pesos computados desde GMB (' + d.period.start + ' → ' + d.period.end + '). Recuerda guardar.',
                            'success'
                        );
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Error desconocido';
                        self.showStatusMsg('Error al cargar: ' + msg, 'error');
                    }
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                              ? xhr.responseJSON.data.message : xhr.statusText;
                    self.showStatusMsg('Error de conexión: ' + msg, 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:3px;margin-right:2px;"></span> Cargar pesos desde GMB'
                    );
                }
            });
        },

        // ── Save ────────────────────────────────────────────────────────────

        saveData: function () {
            var self = this;
            var $btn = $('#oy-busy-save-btn');

            // Sincronizar inputs de stay antes de guardar
            this.data.avg_stay_min = parseInt($('#oy-busy-stay-min').val(), 10) || 45;
            this.data.avg_stay_max = parseInt($('#oy-busy-stay-max').val(), 10) || 90;

            $btn.prop('disabled', true).text('Guardando…');
            this.showStatusMsg('Guardando datos…', 'loading');

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
                        var msg = (response.data && response.data.message) ? response.data.message : '';
                        self.showStatusMsg('Error al guardar: ' + msg, 'error');
                    }
                },
                error: function () {
                    self.showStatusMsg('Error de conexión al guardar. Intenta de nuevo.', 'error');
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
            $s.addClass('oy-busy-status--' + (type || 'info'));
            $s.html(msg).show();

            if (type === 'success') {
                setTimeout(function () { $s.fadeOut(400); }, 4500);
            }
        }

    }; // end OyBusyHours

    // ── Bootstrap ────────────────────────────────────────────────────────────

    $(document).ready(function () {
        if (typeof oyBusyConfig !== 'undefined' && document.getElementById('oy-busy-wrap')) {
            // Usar waitForChart si está disponible (guard del performance metabox)
            if (typeof window.waitForChart === 'function') {
                window.waitForChart(function () {
                    OyBusyHours.init(oyBusyConfig);
                });
            } else if (typeof Chart !== 'undefined') {
                OyBusyHours.init(oyBusyConfig);
            } else {
                // Fallback: esperar 200ms para que Chart.js cargue
                setTimeout(function () {
                    OyBusyHours.init(oyBusyConfig);
                }, 200);
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
   OY Location — Horario de Mayor Concurrencia Metabox
   ========================================================================== */
#oy-busy-wrap { font-size: 13px; color: #1e1e1e; }

/* Notices */
.oy-busy-notice {
    padding: 10px 14px;
    border-radius: 4px;
    margin-bottom: 12px;
    border: 1px solid transparent;
    display: flex;
    align-items: center;
    gap: 8px;
}
.oy-busy-notice--warn {
    background: #fff3cd;
    border-color: #ffc107;
    color: #856404;
}

/* Toolbar */
.oy-busy-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    background: #f6f7f7;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 10px 14px;
    margin-bottom: 14px;
}
.oy-busy-toolbar__left  { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
.oy-busy-toolbar__right { display: flex; gap: 8px; flex-wrap: wrap; }
.oy-busy-field-group    { display: flex; align-items: center; gap: 6px; }
.oy-busy-field-group label { font-weight: 600; white-space: nowrap; font-size: 12px; }
.oy-busy-select { min-width: 160px; }

/* Status message bar */
.oy-busy-status {
    padding: 9px 14px;
    border-radius: 4px;
    margin-bottom: 12px;
    border: 1px solid transparent;
    font-size: 12px;
}
.oy-busy-status--loading { background: #e8f4fb; border-color: #bee5eb; color: #0c5460; }
.oy-busy-status--error   { background: #fff3cd; border-color: #ffc107; color: #856404; }
.oy-busy-status--info    { background: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
.oy-busy-status--success { background: #d4edda; border-color: #c3e6cb; color: #155724; }

/* Day tabs panel */
.oy-busy-days-panel {
    margin-bottom: 0;
}
.oy-busy-day-tabs {
    display: flex;
    align-items: flex-end;
    gap: 0;
    border-bottom: 2px solid #333;
    background: #2d2d2d;
    border-radius: 6px 6px 0 0;
    padding: 8px 8px 0;
    overflow: hidden;
}
.oy-busy-day-tab {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 6px 4px 8px;
    background: transparent;
    border: none;
    cursor: pointer;
    color: #999;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    transition: color .15s;
    position: relative;
}
.oy-busy-day-tab:hover  { color: #ddd; }
.oy-busy-day-tab--active {
    color: #fff;
    border-bottom: 2px solid #fff;
    margin-bottom: -2px;
}

/* Mini weight bars inside each tab */
.oy-busy-day-mini-bar-track {
    width: 20px;
    height: 28px;
    background: rgba(255,255,255,0.08);
    border-radius: 3px 3px 0 0;
    display: flex;
    align-items: flex-end;
    overflow: hidden;
}
.oy-busy-day-mini-bar {
    width: 100%;
    background: rgba(180,180,180,0.55);
    border-radius: 3px 3px 0 0;
    transition: height .35s ease;
    min-height: 3px;
}
.oy-busy-day-tab--active .oy-busy-day-mini-bar {
    background: rgba(255,255,255,0.70);
}
.oy-busy-day-label {
    font-size: 11px;
    margin-top: 2px;
}

/* Chart panel — dark themed */
.oy-busy-chart-panel {
    background: #2d2d2d;
    color: #e8e8e8;
    border-radius: 0 0 6px 6px;
    padding: 14px 18px 16px;
    margin-bottom: 14px;
}

/* Busyness status row */
.oy-busy-status-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
}
.oy-busy-live-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #e53935;
    margin-top: 3px;
    flex-shrink: 0;
    box-shadow: 0 0 6px rgba(229,57,53,.5);
}
.oy-busy-status-title {
    font-weight: 700;
    font-size: 13px;
    color: #fff;
    line-height: 1.3;
}
.oy-busy-status-subtitle {
    font-size: 11px;
    color: #aaa;
    font-style: italic;
    margin-top: 2px;
}

/* Chart canvas container */
.oy-busy-chart-container {
    position: relative;
    height: 160px;
    width: 100%;
    margin-bottom: 12px;
}
.oy-busy-chart-container canvas {
    display: block !important;
    width: 100% !important;
    height: 100% !important;
}

/* Stay row */
.oy-busy-stay-row {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #ccc;
    border-top: 1px solid rgba(255,255,255,.08);
    padding-top: 10px;
    margin-top: 4px;
}
.oy-busy-stay-row strong { color: #fff; }

/* Source row */
.oy-busy-source-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    font-size: 11px;
    color: #999;
}
.oy-busy-source-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}
.oy-busy-computed-at { font-style: italic; }

/* Edit header */
.oy-busy-edit-header {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 12px;
    background: #f0f4ff;
    border: 1px solid #c8d8ff;
    border-radius: 6px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.oy-busy-toggle-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 12px;
    color: #3d4d7a;
}
.oy-busy-toggle-label { display: flex; align-items: center; gap: 4px; }

/* Sliders grid */
.oy-busy-sliders-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
    gap: 8px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 4px;
}
.oy-busy-slider-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}
.oy-busy-slider-label {
    font-size: 10px;
    font-weight: 600;
    color: #555;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.oy-busy-slider-range {
    width: 100%;
    -webkit-appearance: slider-vertical;
    writing-mode: vertical-lr;
    direction: rtl;
    height: 70px;
    accent-color: #4285f4;
}
/* Fallback horizontal si el navegador no soporta vertical */
@media (max-width: 782px) {
    .oy-busy-slider-range {
        writing-mode: horizontal-tb;
        direction: ltr;
        -webkit-appearance: auto;
        height: auto;
        width: 100%;
    }
    .oy-busy-sliders-grid {
        grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    }
}
.oy-busy-slider-val {
    font-size: 11px;
    font-weight: 700;
    color: #4285f4;
    min-width: 26px;
    text-align: center;
}

/* Footer */
.oy-busy-footer {
    font-size: 11px;
    color: #999;
    padding: 8px 2px 4px;
    border-top: 1px solid #eee;
    margin-top: 4px;
    line-height: 1.5;
}
';
	}

}
// end class OY_Location_GMB_BusyHours_Metabox
