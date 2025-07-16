<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Error: $errstr in $errfile on line $errline");
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Exception handler
set_exception_handler(function($exception) {
    error_log("Exception: " . $exception->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        "error" => "Server error",
        "message" => $exception->getMessage(),
        "code" => $exception->getCode(),
        "file" => $exception->getFile(),
        "line" => $exception->getLine()
    ]);
    exit;
});

header('Content-Type: application/json');

try {
    // Fetch AAA Gas Prices page
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://gasprices.aaa.com/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    $html = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200 || empty($html)) {
        throw new Exception("Failed to fetch data from AAA: HTTP $httpCode");
    }

    // Parse the HTML with DOMDocument
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // ✅ FIX: Get all 50 states (main map + small states)
    $states = $xpath->query("//div[contains(@class, 'us-map')]//a | //div[contains(@class, 'small-states')]//a");

    if (!$states || $states->length === 0) {
        throw new Exception("No state elements found.");
    }

    $prices = [];

    foreach ($states as $stateLink) {
        $span = $stateLink->getElementsByTagName('span');
        $hoverBox = $stateLink->getElementsByTagName('div');

        if ($span->length === 0 || $hoverBox->length === 0) continue;

        $stateAbbr = trim($span->item(0)->textContent);
        $paragraphs = $hoverBox->item(0)->getElementsByTagName('p');

        if ($paragraphs->length < 2) continue;

        $stateName = trim(str_replace($stateAbbr, '', $paragraphs->item(0)->textContent));
        $regularPrice = (float)str_replace('$', '', trim($paragraphs->item(1)->textContent));

        $prices[] = [
            'state' => $stateName,
            'stateAbbr' => $stateAbbr,
            'regular' => $regularPrice
        ];
    }

    // ✅ Add national average table values (all fuels)
    $fuelPrices = [];

    $tables = $dom->getElementsByTagName('table');
    if ($tables->length > 0) {
        $tbody = $tables->item(0)->getElementsByTagName('tbody')->item(0);
        $rows = $tbody->getElementsByTagName('tr');

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length >= 6) {
                $label = trim($cells->item(0)->textContent);
                if (in_array($label, ['Current Avg.', 'Yesterday Avg.', 'Week Ago Avg.', 'Month Ago Avg.', 'Year Ago Avg.'])) {
                    $fuelPrices[$label] = [
                        'regular'   => (float)str_replace('$', '', $cells->item(1)->textContent),
                        'midGrade'  => (float)str_replace('$', '', $cells->item(2)->textContent),
                        'premium'   => (float)str_replace('$', '', $cells->item(3)->textContent),
                        'diesel'    => (float)str_replace('$', '', $cells->item(4)->textContent),
                        'e85'       => (float)str_replace('$', '', $cells->item(5)->textContent),
                    ];
                }
            }
        }
    }

    // Final Output
    echo json_encode([
        "lastUpdated" => date("Y-m-d"),
        "states" => $prices,
        "nationalAverage" => $fuelPrices
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        "error" => "Server error",
        "details" => $e->getMessage(),
        "timestamp" => date('Y-m-d H:i:s')
    ]);
}
?>
