import { extend } from 'flarum/common/extend';
import AdminPage, { CommonSettingsItemOptions } from 'flarum/admin/components/AdminPage';
import buildSelectOrInput from './ConfigToComponents/buildSelectOrInput';

export default function() {
    extend(AdminPage.prototype, 'customSettingComponents', function (items) {
        items.add('select-or-input', buildSelectOrInput.bind(this));
    })
}