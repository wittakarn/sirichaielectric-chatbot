<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in production
ini_set('error_log', dirname(__FILE__) . '/logs.log');
require_once '../config.php';

$config = Config::getInstance();
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="dist/vite.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard</title>
    <link rel="stylesheet" href="dist/bundle.css">
    <script>
        // Set API_BASE global variable
        window.WEBSITE_URL = "<?php echo $config->get('website')['url'] ?: ''; ?>";
    </script>
</head>

<body>
    <div id="root"></div>
    <script type="module" src="dist/bundle.js"></script>
</body>

</html>