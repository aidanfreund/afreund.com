<?php
/*
Plugin Name: Weather Calendar Sync
Description: Updates Google Calendar availability based on a 5-day weather forecast.
Version: 1.5
Author: Aidan Freund
*/

// Define constants for API key, coordinates, and thresholds
define('OPENWEATHERMAP_API_KEY', 'eba978219989d8d57de08885c602885');
define('WEATHER_LATITUDE', '40.514202');
define('WEATHER_LONGITUDE', '-88.990631');
define('MAX_TEMPERATURE_CELSIUS', 30); // Example: Book if temperature exceeds 30°C
define('RAIN_THRESHOLD', true); // Example: Book if rain is expected
define('PLUGIN_EVENT_SOURCE', 'weather_calendar_sync'); // Unique identifier for events
define('WEATHER_PAGE_SLUG', 'weather-updates'); // Slug for the page to display weather info

// Schedule the event (run every minute for testing, adjust as needed)
add_action('wp', 'schedule_weather_calendar_sync');
function schedule_weather_calendar_sync() {
    if (!wp_next_scheduled('update_calendar_based_on_forecast')) {
        wp_schedule_event(time(), 'every_minute', 'update_calendar_based_on_forecast');
    }
}
add_filter('cron_schedules', 'add_every_minute_schedule');
function add_every_minute_schedule($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display' => __('Every Minute')
    );
    return $schedules;
}

// Hook the function to the scheduled event
add_action('update_calendar_based_on_forecast', 'update_calendar_based_on_forecast_function');

// Run the update function on plugin activation for immediate testing
register_activation_hook(__FILE__, 'run_weather_calendar_sync_on_activation');
function run_weather_calendar_sync_on_activation() {
    update_calendar_based_on_forecast_function();
    // Add a test event for today on activation
    add_test_calendar_event();
}

// Function to add a basic test event to the calendar today
function add_test_calendar_event() {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

    $client = new Google_Client();
    $client->setApplicationName('weatherintegration');
    $client->setScopes(Google_Service_Calendar::CALENDAR);
    $client->setAuthConfig(plugin_dir_path(__FILE__) . 'credentials.json');

    try {
        $service = new Google_Service_Calendar($client);
        $calendarId = 'primary';

        $todayStart = new DateTime('now', new DateTimeZone('America/Chicago'));
        $todayStart->setTime(10, 0, 0);
        $todayEnd = new DateTime('now', new DateTimeZone('America/Chicago'));
        $todayEnd->setTime(10, 30, 0);

        $testEvent = new Google_Service_Calendar_Event(array(
            'summary' => 'Test Event by Weather Plugin',
            'start' => array(
                'dateTime' => $todayStart->format(DateTime::RFC3339),
                'timeZone' => 'America/Chicago',
            ),
            'end' => array(
                'dateTime' => $todayEnd->format(DateTime::RFC3339),
                'timeZone' => 'America/Chicago',
            ),
        ));

        $createdEvent = $service->events->insert($calendarId, $testEvent);
        error_log('Test event created: ' . $createdEvent->getHtmlLink());

    } catch (Google_Service_Exception $e) {
        error_log('Google Calendar API error (Test Event): ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('An error occurred (Test Event): ' . $e->getMessage());
    }
}

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

    // Array to store weather information for display
    $weatherDisplayData = array();

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
                $rainDescription = '';
                $isTooHot = false;
                $temperature = null;

                if (isset($forecastItem['weather']) && is_array($forecastItem['weather'])) {
                    foreach ($forecastItem['weather'] as $weatherCondition) {
                        if (RAIN_THRESHOLD && strpos(strtolower($weatherCondition['main']), 'rain') !== false) {
                            $isRaining = true;
                            $rainDescription = isset($weatherCondition['description']) ? $weatherCondition['description'] : 'Rain expected';
                            break;
                        }
                    }
                }

                if (isset($forecastItem['main']) && isset($forecastItem['main']['temp'])) {
                    $temperature = $forecastItem['main']['temp'];
                    if ($temperature > MAX_TEMPERATURE_CELSIUS) {
                        $isTooHot = true;
                    }
                }

                if ($isRaining || $isTooHot) {
                    $startDateTime = clone $forecastDate;
                    $startDateTime->setTime(9, 0, 0); // Set start time to 9:00 AM
                    $endDateTime = clone $forecastDate;
                    $endDateTime->setTime(17, 0, 0); // Set end time to 5:00 PM

                    $eventSummary = ($isRaining ? 'Unavailable due to rain' : '') . ($isRaining && $isTooHot ? ' and ' : '') . ($isTooHot ? 'Unavailable due to heat' : '');

                    $event = new Google_Service_Calendar_Event(array(
                        'summary' => $eventSummary,
                        'start' => array(
                            'dateTime' => $startDateTime->format(DateTime::RFC3339),
                            'timeZone' => 'America/Chicago',
                        ),
                        'end' => array(
                            'dateTime' => $endDateTime->format(DateTime::RFC3339),
                            'timeZone' => 'America/Chicago',
                        ),
                        'extendedProperties' => array(
                            'private' => array(
                                'source' => PLUGIN_EVENT_SOURCE,
                            ),
                        ),
                    ));

                    // Check if an event already exists for this day based on the source
                    $events = $service->events->listEvents($calendarId, array(
                        'timeMin' => $startDateTime->format(DateTime::RFC3339),
                        'timeMax' => $endDateTime->format(DateTime::RFC3339),
                        'singleEvents' => true,
                        'privateExtendedProperty' => 'source=' . PLUGIN_EVENT_SOURCE,
                    ));

                    $eventExists = false;
                    foreach ($events->getItems() as $existingEvent) {
                        if ($existingEvent->getSummary() === $eventSummary) {
                            $eventExists = true;
                            break;
                        }
                    }

                    if (!$eventExists) {
                        $createdEvent = $service->events->insert($calendarId, $event);
                        error_log('Calendar event created: ' . $createdEvent->getHtmlLink() . ' - Summary: ' . $eventSummary . ' - Date: ' . $forecastDate->format('Y-m-d'));
                    } else {
                        error_log('Calendar event already exists for ' . $forecastDate->format('Y-m-d') . ' due to ' . ($isRaining ? 'rain' : 'heat'));
                    }

                    // Prepare weather info for display
                    if ($isRaining) {
                        $weatherDisplayData[$forecastDate->format('Y-m-d')] = 'Raining: ' . $rainDescription;
                    } elseif ($isTooHot) {
                        $weatherDisplayData[$forecastDate->format('Y-m-d')] = 'Too Hot: ' . round($temperature) . '°C';
                    }
                }
            }
        }

        // Update the weather display page
        update_option('weather_calendar_sync_data', $weatherDisplayData);

    } catch (Google_Service_Exception $e) {
        error_log('Google Calendar API error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('An error occurred: ' . $e->getMessage());
    }
}

// Function to display weather information on a chosen page
add_action('the_content', 'display_weather_information');
function display_weather_information($content) {
    if (is_page(WEATHER_PAGE_SLUG)) {
        $weatherData = get_option('weather_calendar_sync_data');
        if (!empty($weatherData)) {
            $content .= '<h2>Weather Forecast for Unavailable Days:</h2><ul>';
            foreach ($weatherData as $date => $info) {
                $content .= '<li>' . $date . ': ' . esc_html($info) . '</li>';
            }
            $content .= '</ul>';
        } else {
            $content .= '<p>No weather updates for unavailable days.</p>';
        }
    }
    return $content;
}

// Create the weather update page if it doesn't exist
register_activation_hook(__FILE__, 'create_weather_update_page');
function create_weather_update_page() {
    $page_slug = WEATHER_PAGE_SLUG;
    $page = get_page_by_path($page_slug);
    if (!$page) {
        wp_insert_post(array(
            'post_title' => 'Weather Updates',
            'post_name' => $page_slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ));
    }
}
