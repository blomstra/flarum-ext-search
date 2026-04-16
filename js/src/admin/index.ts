import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import DashboardPage from 'flarum/admin/components/DashboardPage';
import DashboardWidget from 'flarum/admin/components/DashboardWidget';
import Alert from 'flarum/common/components/Alert';

const REQUIRED_INDEX_COMPAT = 'v3';

class ReindexWarningWidget extends DashboardWidget {
  className() {
    return 'ReindexWarningWidget';
  }

  content() {
    return m(
      Alert,
      {
        type: 'warning',
        dismissible: false,
        icon: 'fas fa-exclamation-triangle',
        title: app.translator.trans('blomstra-search.admin.reindex-required.title'),
      },
      app.translator.trans('blomstra-search.admin.reindex-required.detail')
    );
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

  const languages: Record<string, string> = {
    arabic:     'Arabic',
    armenian:   'Armenian',
    basque:     'Basque',
    bengali:    'Bengali',
    brazilian:  'Brazilian Portuguese',
    bulgarian:  'Bulgarian',
    catalan:    'Catalan',
    cjk:        'CJK (Chinese, Japanese, Korean)',
    czech:      'Czech',
    danish:     'Danish',
    dutch:      'Dutch',
    english:    'English',
    estonian:   'Estonian',
    finnish:    'Finnish',
    french:     'French',
    galician:   'Galician',
    german:     'German',
    greek:      'Greek',
    hindi:      'Hindi',
    hungarian:  'Hungarian',
    indonesian: 'Indonesian',
    irish:      'Irish',
    italian:    'Italian',
    latvian:    'Latvian',
    lithuanian: 'Lithuanian',
    norwegian:  'Norwegian',
    persian:    'Persian',
    portuguese: 'Portuguese',
    romanian:   'Romanian',
    russian:    'Russian',
    sorani:     'Sorani (Kurdish)',
    spanish:    'Spanish',
    swedish:    'Swedish',
    turkish:    'Turkish',
    thai:       'Thai',
  };

  app.extensionData
    .for('blomstra-search')
    .registerSetting(function (this: any) {
      // No index yet, or first build predates this tracking — stay silent.
      const indexedAnalyzer = app.data.settings['blomstra-search.indexed-analyzer'];
      if (!activeIndex || !indexedAnalyzer) return null;

      const currentAnalyzer = this.setting('blomstra-search.analyzer-language')() || 'english';
      const currentStemExclusion = this.setting('blomstra-search.stem-exclusion')() || '';
      const indexedStemExclusion = app.data.settings['blomstra-search.indexed-stem-exclusion'] || '';

      if (currentAnalyzer === indexedAnalyzer && currentStemExclusion === indexedStemExclusion) return null;

      return m(
        Alert,
        { type: 'warning', dismissible: false, icon: 'fas fa-exclamation-triangle' },
        app.translator.trans('blomstra-search.admin.index-settings-changed')
      );
    })
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
      setting: 'blomstra-search.search-discussion-subjects',
      label: app.translator.trans('blomstra-search.admin.search-discussion-subjects'),
      type: 'switch',
    })
    .registerSetting({
      setting: 'blomstra-search.search-post-bodies',
      label: app.translator.trans('blomstra-search.admin.search-post-bodies'),
      type: 'switch',
    })
    .registerSetting({
      setting: 'blomstra-search.analyzer-language',
      label: app.translator.trans('blomstra-search.admin.analyzer.label'),
      help: app.translator.trans('blomstra-search.admin.analyzer.help'),
      type: 'select',
      options: languages,
      default: 'english',
    })
    .registerSetting(function (this: any) {
      const isCjk = (this.setting('blomstra-search.analyzer-language')() || 'english') === 'cjk';
      return this.buildSettingComponent({
        setting: 'blomstra-search.stem-exclusion',
        type: 'textarea',
        label: app.translator.trans('blomstra-search.admin.settings.stem-exclusion.label'),
        help: app.translator.trans('blomstra-search.admin.settings.stem-exclusion.help'),
        disabled: isCjk,
      });
    })
    .registerSetting(function (this: any) {
      const isCjk = (this.setting('blomstra-search.analyzer-language')() || 'english') === 'cjk';
      return this.buildSettingComponent({
        setting: 'blomstra-search.min-search-length',
        label: app.translator.trans('blomstra-search.admin.min-search-length.label'),
        help: app.translator.trans('blomstra-search.admin.min-search-length.help'),
        type: 'select',
        options: { '1': '1', '2': '2', '3': '3', '4': '4' },
        default: app.data.settings['blomstra-search.min-search-length'],
        disabled: isCjk,
      });
    });
});
