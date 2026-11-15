<?php

return [
    'title' => 'Loggopplasting',
    'brand' => 'Merkevare',
    'domain' => 'Domene',
    'file' => 'Gzip-komprimert tilgangslogg',
    'instructions' => 'Last opp en komplett nginx- eller Apache-logg i combined-format for det valgte domenet. For hvert UTC-døgn i filen erstatter opplastingen de lagrede tellingene.',
    'upload' => 'Last opp logg',
    'unavailable' => 'Loggopplasting krever en aktiv tenant og en merkevare med registrert domene uten ClickHouse-integrasjon.',
    'recent_uploads' => 'Siste opplastinger',
    'pending' => 'I kø',
    'processed' => 'Behandlet',
    'rejected' => 'Importen mislyktes',
    'clickhouse_enabled' => 'Denne merkevaren bruker allerede ClickHouse.',
    'invalid_gzip' => 'Velg en gzip-komprimert loggfil.',
    'invalid_date' => 'Angi et avsluttet UTC-døgn i formatet ÅÅÅÅ-MM-DD.',
];
