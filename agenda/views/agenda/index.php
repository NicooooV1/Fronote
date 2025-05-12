<?php session_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Pronote Web</title>
    <link rel="stylesheet" href="/public/css/agenda.css">
</head>
<body>
<div id="calendar"></div>
<button id="export-ics">Exporter (.ics)</button>

<script>
    const calendarEl = document.getElementById('calendar');
</script>
<script src="/public/js/agenda.js"></script>
</body>
</html>