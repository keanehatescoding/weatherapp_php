<?php
/**
 * Weather alerts helper.
 *
 * Renders government/agency severe-weather alerts from the OpenWeatherMap
 * One Call 3.0 `alerts` array (absent when no active alerts exist).
 */
require_once __DIR__ . '/utils.php';

class Alerts {
	/**
	 * Render active weather alerts as HTML.
	 *
	 * @param array $alerts   OpenWeatherMap One Call 3.0 `alerts` array. Each
	 *                        entry has `sender_name`, `event`, `start`, `end`,
	 *                        and `description`.
	 * @param int   $tzOffset Location UTC offset in seconds, for local times.
	 * @return string HTML markup (sanitized).
	 */
	public static function displayAlerts(array $alerts, int $tzOffset): string {
		if ($alerts === []) {
			return '';
		}

		$html = '';
		foreach ($alerts as $alert) {
			$event  = $alert['event'] ?? 'Weather Alert';
			$sender = $alert['sender_name'] ?? '';
			$desc   = $alert['description'] ?? '';
			$start  = isset($alert['start']) ? (int)$alert['start'] + $tzOffset : null;
			$end    = isset($alert['end'])   ? (int)$alert['end']   + $tzOffset : null;

			$html .= '<div class="alert alert-danger mb-3" role="alert">';
			$html .= '<h5 class="fw-bold mb-2">⚠️ ' . esc($event) . '</h5>';
			if ($sender !== '') {
				$html .= '<p class="small mb-2">Issued by ' . esc($sender) . '</p>';
			}
			if ($start !== null && $end !== null) {
				$html .= '<p class="small mb-2">' . esc(gmdate('M j, g:i a', $start))
					. ' – ' . esc(gmdate('M j, g:i a', $end)) . '</p>';
			}
			if ($desc !== '') {
				$html .= '<p class="mb-0">' . nl2br(esc($desc)) . '</p>';
			}
			$html .= '</div>';
		}
		return $html;
	}
}
