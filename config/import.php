<?php

/*
|--------------------------------------------------------------------------
| Property URL import
|--------------------------------------------------------------------------
|
| Pages and images are fetched by this server through App\Services\Import\
| SafeHttpClient: public IPs only, ports 80/443, every redirect re-checked,
| strict size, type and time limits, and robots.txt respected. Sites that
| block automated access are never worked around; users upload manually.
|
*/

return [

    'user_agent' => env('IMPORT_USER_AGENT') ?: 'Mozilla/5.0 (compatible; PropertyImporter/1.0)',

    // Product token matched against robots.txt "User-agent" lines.
    'robots_token' => 'PropertyImporter',

    'connect_timeout' => 5,
    'timeout' => 20,
    'max_redirects' => 5,

    'max_page_bytes' => 3 * 1024 * 1024,
    'max_image_bytes' => 20 * 1024 * 1024,
    'max_robots_bytes' => 512 * 1024,

    // Images offered for selection, and how many can be imported at once.
    'max_candidates' => 60,
    'max_images' => 30,

    // Smaller images (icons, logos, thumbnails) are skipped.
    'min_image_width' => 600,

    // Characters of visible page text sent to the AI for extraction.
    'max_text_chars' => 12000,

];
