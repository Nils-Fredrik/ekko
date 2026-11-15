<?php

return [
    'title' => 'Logguppladdning',
    'brand' => 'Varumärke',
    'domain' => 'Domän',
    'file' => 'Gzip-komprimerad åtkomstlogg',
    'instructions' => 'Ladda upp en komplett nginx- eller Apache-logg i combined-format för vald domän. Uppladdningen ersätter de sparade värdena för varje UTC-dygn i filen.',
    'upload' => 'Ladda upp logg',
    'unavailable' => 'Logguppladdning kräver en aktiv klientorganisation och ett varumärke med registrerad domän utan ClickHouse-integration.',
    'recent_uploads' => 'Senaste uppladdningar',
    'pending' => 'I kö',
    'processed' => 'Bearbetad',
    'rejected' => 'Importen misslyckades',
    'clickhouse_enabled' => 'Det här varumärket använder redan ClickHouse.',
    'invalid_gzip' => 'Välj en gzip-komprimerad loggfil.',
    'invalid_date' => 'Ange ett avslutat UTC-dygn i formatet ÅÅÅÅ-MM-DD.',
];
