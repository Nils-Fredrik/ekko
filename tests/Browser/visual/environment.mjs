// Fixture output must not depend on a developer's preferred application/Faker locale.
export const fixtureEnvironment = {
    APP_LOCALE: 'nb',
    APP_FALLBACK_LOCALE: 'nb',
    APP_FAKER_LOCALE: 'nb_NO',
    EKKO_UI_QUALITY: '1',
};
