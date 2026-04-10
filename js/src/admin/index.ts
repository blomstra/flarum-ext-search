import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import DashboardPage from 'flarum/admin/components/DashboardPage';
import DashboardWidget from 'flarum/admin/components/DashboardWidget';
import Alert from 'flarum/common/components/Alert';

const REQUIRED_INDEX_COMPAT = 'v2';

class ReindexWarningWidget extends DashboardWidget {
  className() {
    return 'ReindexWarningWidget';
  }

  content() {
    return m(Alert, {
      type: 'warning',
      dismissible: false,
      icon: 'fas fa-exclamation-triangle',
      title: app.translator.trans('blomstra-search.admin.reindex-required.title'),
    }, app.translator.trans('blomstra-search.admin.reindex-required.detail'));
  }
}

app.initializers.add('blomstra-search', () => {
  const activeIndex = app.data.settings['blomstra-search.active-index'];
  const compatVersion = app.data.settings['blomstra-search.index-compatible'];

  if (activeIndex && compatVersion !== REQUIRED_INDEX_COMPAT) {
    extend(DashboardPage.prototype, 'availableWidgets', function (items) {
      items.add('blomstra-search-reindex', m(ReindexWarningWidget), 110);
    });
  }

  const languages = new Map();
  [
    'arabic',
    'armenian',
    'basque',
    'bengali',
    'brazilian',
    'bulgarian',
    'catalan',
    'cjk',
    'czech',
    'danish',
    'dutch',
    'english',
    'estonian',
    'finnish',
    'french',
    'galician',
    'german',
    'greek',
    'hindi',
    'hungarian',
    'indonesian',
    'irish',
    'italian',
    'latvian',
    'lithuanian',
    'norwegian',
    'persian',
    'portuguese',
    'romanian',
    'russian',
    'sorani',
    'spanish',
    'swedish',
    'turkish',
    'thai',
  ].forEach((language) => {
    languages.set(language, language);
  });

  app.extensionData
    .for('blomstra-search')
    .registerSetting({
      setting: 'blomstra-search.elastic-endpoint',
      label: app.translator.trans('blomstra-search.admin.elastic-endpoint'),
      type: 'input',
    })
    .registerSetting({
      setting: 'blomstra-search.elastic-username',
      label: app.translator.trans('blomstra-search.admin.elastic-username'),
      type: 'input',
    })
    .registerSetting({
      setting: 'blomstra-search.elastic-password',
      label: app.translator.trans('blomstra-search.admin.elastic-password'),
      type: 'password',
    })
    .registerSetting({
      setting: 'blomstra-search.elastic-index',
      label: app.translator.trans('blomstra-search.admin.elastic-index'),
      default: 'flarum',
      type: 'input',
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
      setting: 'blomstra-search.search-discussion-subjects',
      label: app.translator.trans('blomstra-search.admin.search-discussion-subjects'),
      type: 'switch',
    })
    .registerSetting({
      setting: 'blomstra-search.search-post-bodies',
      label: app.translator.trans('blomstra-search.admin.search-post-bodies'),
      type: 'switch',
    });
});
