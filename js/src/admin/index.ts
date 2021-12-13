import app from 'flarum/admin/app';

app.initializers.add('blomstra-search', () => {
    const languages = new Map;
    ['arabic', 'armenian', 'basque', 'bengali', 'brazilian', 'bulgarian', 'catalan', 'cjk', 'czech',
        'danish', 'dutch', 'english', 'estonian', 'finnish', 'french', 'galician', 'german', 'greek',
        'hindi', 'hungarian', 'indonesian', 'irish', 'italian', 'latvian', 'lithuanian', 'norwegian',
        'persian', 'portuguese', 'romanian', 'russian', 'sorani', 'spanish', 'swedish', 'turkish', 'thai']
        .forEach(language => {
            languages.set(language, language);
        });

    app.extensionData
        .for('blomstra-search')
        .registerSetting({
            setting: 'blomstra-search.elastic-endpoint',
            label: app.translator.trans('blomstra-search.admin.elastic-endpoint'),
            type: 'input'
        })
        .registerSetting({
            setting: 'blomstra-search.elastic-username',
            label: app.translator.trans('blomstra-search.admin.elastic-username'),
            type: 'input'
        })
        .registerSetting({
            setting: 'blomstra-search.elastic-password',
            label: app.translator.trans('blomstra-search.admin.elastic-password'),
            type: 'password'
        })
        .registerSetting({
            setting: 'blomstra-search.elastic-index',
            label: app.translator.trans('blomstra-search.admin.elastic-index'),
            default: 'flarum',
            type: 'input'
        })
        .registerSetting({
            setting: 'blomstra-search.analyzer-language',
            label: app.translator.trans('blomstra-search.admin.analyzer.label'),
            help: app.translator.trans('blomstra-search.admin.analyzer.help'),
            type: 'select',
            options: Object.fromEntries(languages.entries()),
            default: 'english',
        })
        .registerSetting({
            setting: 'blomstra-search.elastic-index',
            label: app.translator.trans('blomstra-search.admin.elastic-index'),
            default: 'flarum',
            type: 'input'
        })
});
