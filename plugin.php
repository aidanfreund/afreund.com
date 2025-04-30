<?php
/*
Plugin Name: Weather Calendar Sync
Description: Updates Google Calendar availability based on a 5-day weather forecast.
Version: 1.3
Author: Aidan Freund
*/

// Define constants for API key, coordinates, and thresholds
define('OPENWEATHERMAP_API_KEY', 'eba978219989d8d57de08885c602885');
define('WEATHER_LATITUDE', '40.514202');
define('WEATHER_LONGITUDE', '-88.990631');
define('MAX_TEMPERATURE_CELSIUS', 30); // Example: Book if temperature exceeds 30°C
define('RAIN_THRESHOLD', true); // Example: Book if rain is expected

// Schedule the event (run daily for forecast)
add_action('wp', 'schedule_weather_calendar_sync');
function schedule_weather_calendar_sync() {
    if (!wp_next_scheduled('update_calendar_based_on_forecast')) {
        wp_schedule_event(strtotime('00:00 tomorrow'), 'daily', 'update_calendar_based_on_forecast');
    }
}

// Hook the function to the scheduled event
add_action('update_calendar_based_on_forecast', 'update_calendar_based_on_forecast_function');

// Function to update calendar based on 5-day weather forecast
function update_calendar_based_on_forecast_function() {
    $apiKey = OPENWEATHERMAP_API_KEY;
    $latitude = WEATHER_LATITUDE;
    $longitude = WEATHER_LONGITUDE;
    $forecastUrl = "http://api.openweathermap.org/data/2.5/forecast?lat={$latitude}&lon={$longitude}&appid={$apiKey}&units=metric"; // Using metric for Celsius

    $response = wp_remote_get($forecastUrl);

    if (is_wp_error($response)) {
        error_log('Error fetching weather forecast: ' . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $forecastData = json_decode($body, true);

    if (empty($forecastData) || !isset($forecastData['list'])) {
        error_log('Error decoding weather forecast data.');
        return;
    }

    // Include the Google API client library
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

    $client = new Google_Client();
    $client->setApplicationName('weatherintegration');
    $client->setScopes(Google_Service_Calendar::CALENDAR);
    $client->setAuthConfig(plugin_dir_path(__FILE__) . 'credentials.json'); // Ensure credentials.json is in your plugin directory

    try {
        $service = new Google_Service_Calendar($client);
        $calendarId = 'primary';

        foreach ($forecastData['list'] as $forecastItem) {
            $timestamp = $forecastItem['dt'];
            $forecastDate = new DateTime('@' . $timestamp);
            $forecastDate->setTimezone(new DateTimeZone('America/Chicago'));
            $today = new DateTime('now', new DateTimeZone('America/Chicago'));
            $today->setTime(0, 0, 0);
            $diff = $today->diff($forecastDate)->days;

            // Only process the forecast for the next 5 days
            if ($diff >= 0 && $diff < 5) {
                $isRaining = false;
                if (isset($forecastItem['weather']) && is_array($forecastItem['weather'])) {
                    foreach ($forecastItem['weather'] as $weatherCondition) {
                        if (RAIN_THRESHOLD && strpos(strtolower($weatherCondition['main']), 'rain') !== false) {
                            $isRaining = true;
                            break;
                        }
                    }
                }

                $isTooHot = false;
                if (isset($forecastItem['main']) && isset($forecastItem['main']['temp']) && $forecastItem['main']['temp'] > MAX_TEMPERATURE_CELSIUS) {
                    $isTooHot = true;
                }

                if ($isRaining || $isTooHot) {
                    $startDateTime = clone $forecastDate;
                    $startDateTime->setTime(9, 0, 0); // Set start time to 9:00 AM
                    $endDateTime = clone $forecastDate;
                    $endDateTime->setTime(17, 0, 0); // Set end time to 5:00 PM

                    $event = new Google_Service_Calendar_Event(array(
                        'summary' => ($isRaining ? 'Unavailable due to rain' : '') . ($isRaining && $isTooHot ? ' and ' : '') . ($isTooHot ? 'Unavailable due to heat' : ''),
                        'start' => array(
                            'dateTime' => $startDateTime->format(DateTime::RFC3339),
                            'timeZone' => 'America/Chicago',
                        ),
                        'end' => array(
                            'dateTime' => $endDateTime->format(DateTime::RFC3339),
                            'timeZone' => 'America/Chicago',
                        ),
                    ));

                    // Check if an event already exists for this day to avoid duplicates
                    $events = $service->events->listEvents($calendarId, array(
                        'timeMin' => $startDateTime->format(DateTime::RFC3339),
                        'timeMax' => $endDateTime->format(DateTime::RFC3339),
                        'singleEvents' => true,
                        'q' => 'Unavailable due to rain', // Basic check, can be improved
                    ));

                    $heatEvents = $service->events->listEvents($calendarId, array(
                        'timeMin' => $startDateTime->format(DateTime::RFC3339),
                        'timeMax' => $endDateTime->format(DateTime::RFC3339),
                        'singleEvents' => true,
                        'q' => 'Unavailable due to heat', // Basic check, can be improved
                    ));

                    if (empty($events->getItems()) && $isRaining) {
                        $createdEvent = $service->events->insert($calendarId, $event);
                        error_log('Rain event created: ' . $createdEvent->getHtmlLink());
                    } elseif (empty($heatEvents->getItems()) && $isTooHot) {
                        $createdEvent = $service->events->insert($calendarId, $event);
                        error_log('Heat event created: ' . $createdEvent->getHtmlLink());
                    }
                }
            }
        }
    } catch (Google_Service_Exception $e) {
        error_log('Google Calendar API error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('An error occurred: ' . $e->getMessage());
    }
}
