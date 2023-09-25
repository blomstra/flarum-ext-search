import type Mithril from 'mithril';
import type Stream from 'mithril/stream';
import app from 'flarum/admin/app';
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

interface StreamWithMark<T> extends Stream<T> {
    mark?: true;
}

export default function (this: AdminPage, _entry: CommonSettingsItemOptions) {
    // do this cast because function signature is restricted
    const entry = _entry as SelectOrInputSettingComponentOptionsWithoutType;
    const [inputId, helpTextId] = [generateElementId(), generateElementId()];
    const { setting, help, label, ...componentAttrs } = entry;
    const { default: defaultValue, options, checkboxDescription, ...otherAttrs } = componentAttrs;
    
    let settingElement: Mithril.Children;
    // Trick: add a marker property to the AdminPage's setting stream,
    // which contructs and destructs with AdminPage so that default is
    // only loaded when the page is created.
    // Default should only be loaded once in a page because when checkbox
    // is checked, SelectOrInput will set its value to empty string so that
    // it becomes an empty input. If default is loaded every time when value
    // is falsy, SelectOrInput will not work properly.
    const settingStream = this.settings[setting] as StreamWithMark<string>;
    let value = settingStream();
    if (!settingStream.mark) {
        settingStream.mark = true;
        if (!value) {
            app.data.settings[setting] = defaultValue;
            settingStream(defaultValue);
            value = settingStream();
        }
    }

    settingElement = (
        <SelectOrInput
            id={inputId}
            aria-describedby={helpTextId}
            value={value}
            options={options}
            onchange={settingStream}
            checkboxDescription={checkboxDescription}
            {...otherAttrs}
        />
    );

    return (
        <div className="Form-group">
            {label && <label for={inputId}>{label}</label>}
            <div id={helpTextId} className="helpText">{help}</div>
            {settingElement}
        </div>
    );
}
