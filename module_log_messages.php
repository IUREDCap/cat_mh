<?php

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

echo "<pre>";

$pid = $module->getProjectId();

$result = $module->queryLogs("SELECT message, timestamp, sequence, subjectID, scheduled_datetime, record");
while ($row = db_fetch_assoc($result)) {
	print_r($row);
	echo "\n";
}

echo "</pre>";
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
?>