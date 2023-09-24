import type Mithril from 'mithril';
import AdminPage, { CommonSettingsItemOptions } from 'flarum/admin/components/AdminPage';
import SelectOrInput from "../../common/Components/SelectOrInput";
import generateElementId from 'flarum/admin/utils/generateElementId';

// forgive me, but this interface is just what its name implies
interface SelectOrInputSettingComponentOptionsWithoutType extends CommonSettingsItemOptions {
    options: { [value: string]: Mithril.Children };
    checkboxDescription: string;
    default: string;
}

export interface SelectOrInputSettingComponentOptions extends SelectOrInputSettingComponentOptionsWithoutType {
    type: 'select-or-input';
}

var firstLoadCompletedSet: Set<string> = new Set();

export default function (this: AdminPage, _entry: CommonSettingsItemOptions) {
    // do this cast because function signature is restricted
    const entry = _entry as SelectOrInputSettingComponentOptionsWithoutType;
    const [inputId, helpTextId] = [generateElementId(), generateElementId()];
    const { setting, help, label, ...componentAttrs } = entry;
    const { default: defaultValue, options, checkboxDescription, ...otherAttrs } = componentAttrs;
    const value = this.setting(setting)();
    let settingElement: Mithril.Children;

    const firstLoadCompleted = firstLoadCompletedSet.has(entry.setting);

    settingElement = (
        <SelectOrInput
            id={inputId}
            aria-describedby={helpTextId}
            value={(!firstLoadCompleted && !value) ? defaultValue : value}
            options={options}
            onchange={this.settings[setting]}
            checkboxDescription={checkboxDescription}
            {...otherAttrs}
        />
    );

    if (!firstLoadCompleted) firstLoadCompletedSet.add(entry.setting);

    return (
        <div className="Form-group">
            {label && <label for={inputId}>{label}</label>}
            <div id={helpTextId} className="helpText">{help}</div>
            {settingElement}
        </div>
    );
}
