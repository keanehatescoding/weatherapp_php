<?php declare(strict_types=1);
/**
 * Hourly forecast helper.
 *
 * Builds a horizontally-scrollable strip showing the next several hours of
 * weather from the OpenWeatherMap One Call 3.0 `hourly` array.
 */
require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/utils.php';

class Hourly {
    /** How many hourly slots to display. */
    const SLOTS = 12;

    /**
     * Render the hourly forecast as HTML.
     *
     * @param array $hourly    OpenWeatherMap One Call 3.0 `hourly` array. Each
     *                         entry has `dt`, `temp`, `pop` and `weather`.
     * @param int   $tzOffset  Location UTC offset in seconds, for local times.
     * @param string $deg      Degree unit suffix ('°C' or '°F').
     * @return string HTML markup (sanitized).
     */
    public static function displayHourly(array $hourly, int $tzOffset, string $deg): string {
        if ($hourly === []) {
            return '';
        }

        $hourly = array_slice($hourly, 0, self::SLOTS);

        $html  = '';
        $html .= '<div class="hourly-container mt-4">';
        $html .= '<h3 class="text-center mb-3 text-white">Hourly Forecast</h3>';
        $html .= '<div class="hourly-scroll" tabindex="0" role="group" aria-label="Hourly forecast, scrollable">';

        foreach ($hourly as $hour) {
            $dt    = (int)($hour['dt'] ?? time()) + $tzOffset;
            $icon  = $hour['weather'][0]['icon'] ?? '';
            $emoji = Icons::get($icon);
            $temp  = round((float)($hour['temp'] ?? 0));
            $pop   = (int)round(((float)($hour['pop'] ?? 0)) * 100);

            $html .= '<div class="hourly-item text-center p-3 border rounded bg-white bg-opacity-90 shadow-sm">';
            $html .= '<p class="mb-1 small fw-bold text-dark">' . esc(gmdate('g A', $dt)) . '</p>';
            $html .= '<div class="weather-emoji mb-1" style="font-size: 1.8rem;">' . $emoji . '</div>';
            $html .= '<p class="mb-1 text-dark"><strong>' . esc((int) $temp) . $deg . '</strong></p>';
            if ($pop > 0) {
                $html .= '<p class="small text-primary mb-0">💧 ' . esc($pop) . '%</p>';
            }
            $html .= '</div>';
        }

        $html .= '</div>'; // /.hourly-scroll
        $html .= '</div>'; // /.hourly-container
        return $html;
    }
}
