/*
Plugin Name: Weather Calendar Sync
Description: Updates Google Calendar availability based on weather forecast.
Version: 1.0
Author: Your Name
*/
// Add custom interval
add_filter('cron_schedules', 'add_five_minute_interval');
function add_five_minute_interval($schedules) {
    $schedules['five_minutes'] = array(
        'interval' => 300, // 300 seconds = 5 minutes
        'display' => __('Every 5 Minutes')
    );
    return $schedules;
}
// Schedule the event
if (!wp_next_scheduled('update_calendar_based_on_weather')) {
    wp_schedule_event(time(), 'five_minutes', 'update_calendar_based_on_weather');
}
// Hook the function
add_action('update_calendar_based_on_weather', 'update_calendar_based_on_weather_function');
// Function to update calendar
function update_calendar_based_on_weather_function() {
    $apiKey = 'eba978219989d8d57de08885c602885';
    $latitude = '40.514202';
    $longitude = '-88.990631';
    $weatherUrl = "http://api.openweathermap.org/data/2.5/weather?lat={$latitude}&lon={$longitude}&appid={$apiKey}";
    $weatherData = file_get_contents($weatherUrl);
    $weather = json_decode($weatherData, true);
    if ($weather['weather'][0]['main'] == 'Rain') {
        require_once 'path/to/google-api-php-client/vendor/autoload.php';        //find this
        $client = new Google_Client();
        $client->setApplicationName('weatherintegration');
        $client->setScopes(Google_Service_Calendar::CALENDAR);
        $client->setAuthConfig('Desktop/credentials.json');
        $service = new Google_Service_Calendar($client);
        // Get current date and time
        $startDateTime = new DateTime('now', new DateTimeZone('America/Chicago'));
        $endDateTime = clone $startDateTime;
        $endDateTime->setTime(17, 0); // Set end time to 5:00 PM
        $event = new Google_Service_Calendar_Event(array(
            'summary' => 'Unavailable due to rain',
            'start' => array(
                'dateTime' => $startDateTime->format(DateTime::RFC3339),
                'timeZone' => 'America/Chicago',
            ),
            'end' => array(
                'dateTime' => $endDateTime->format(DateTime::RFC3339),
                'timeZone' => 'America/Chicago',
            ),
        ));
        $calendarId = 'primary';
        $service->events->insert($calendarId, $event);
    }
}


