<?php
// auto_prepend_file for the demo dev server: point db.php at the demo copy.
putenv('KARTFOLIO_DB=' . realpath(__DIR__ . '/../private/data') . '/demo_territory.db');
