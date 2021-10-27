import app from 'flarum/forum/app';

app.initializers.add('blomstra-search', (app) => {
    const languages = {...['arabic', 'armenian', 'basque', 'bengali', 'brazilian', 'bulgarian', 'catalan', 'cjk', 'czech',
        'danish', 'dutch', 'english', 'estonian', 'finnish', 'french', 'galician', 'german', 'greek',
        'hindi', 'hungarian', 'indonesian', 'irish', 'italian', 'latvian', 'lithuanian', 'norwegian',
        'persian', 'portuguese', 'romanian', 'russian', 'sorani', 'spanish', 'swedish', 'turkish', 'thai']}
    app.extensionData
        .for('blomstra-search')
        .registerSetting(
            {
                setting: 'blomstra-search.analyzer-language', // This is the key the settings will be saved under in the settings table in the database.
                label: app.translator.trans('blomstra-search.admin.analyzer.label'), // The label to be shown letting the admin know what the setting does.
                help: app.translator.trans('blomstra-search.admin.analyzer.help'), // Optional help text where a longer explanation of the setting can go.
                type: 'select', // What type of setting this is, valid options are: boolean, text (or any other <input> tag type), and select.
                options: languages,
                default: null,
            },
            30 // Optional: Priority
        )
});
