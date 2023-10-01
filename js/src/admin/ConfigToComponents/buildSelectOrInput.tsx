import type Mithril from 'mithril';
import Stream from 'flarum/common/utils/Stream';
import classList from 'flarum/common/utils/classList';
import withAttr from 'flarum/common/utils/withAttr';
import AdminPage, { CommonSettingsItemOptions } from 'flarum/admin/components/AdminPage';
import Select from 'flarum/common/components/Select';
import generateElementId from 'flarum/admin/utils/generateElementId';

// forgive me, but this interface is just what its name implies
interface SelectOrInputSettingComponentOptionsWithoutType extends CommonSettingsItemOptions {
    options: { [value: string]: Mithril.Children };
    inputDescription: string;
    default: string;
}

export interface SelectOrInputSettingComponentOptions extends SelectOrInputSettingComponentOptionsWithoutType {
    type: 'select-or-input';
}

interface StreamWithUpstream<T> extends Stream<T> {
    upstreams?: Array<Stream<unknown>>;
}

function mergeSelectOrInput(
    selectStream: Stream<string>, inputStream: Stream<string>,
    changed: Array<Stream<unknown>>
) {
    return inputStream() || selectStream();
}

export default function (this: AdminPage, _entry: CommonSettingsItemOptions) {
    // do this cast because function signature is restricted
    const entry = _entry as SelectOrInputSettingComponentOptionsWithoutType;
    const [selectId, inputId, helpTextId] = [generateElementId(), generateElementId(), generateElementId()];
    const { setting, help, label, ...componentAttrs } = entry;
    const { default: defaultValue, options, inputDescription, className, ...otherAttrs } = componentAttrs;

    let settingStream = this.settings[setting] as StreamWithUpstream<string>;
    
    if (!settingStream.upstreams) {
        let value = settingStream() || defaultValue;
        let selectStream = Stream();
        let inputStream = Stream();
        let upstreams = [selectStream, inputStream]
        settingStream = Stream.combine(mergeSelectOrInput, upstreams);
        settingStream.upstreams = upstreams;
        let useSelect = Object.keys(options).indexOf(value) > -1;
        if (useSelect) {
            selectStream(value);
            inputStream('');
        } else {
            selectStream(defaultValue);
            inputStream(value);
        }
        this.settings[setting] = settingStream;
    }

    let [selectStream, inputStream] = settingStream.upstreams!;

    let selectElement = (
        <Select
            className={className}
            id={selectId}
            aria-describedby={helpTextId}
            value={selectStream()}
            options={options}
            onchange={selectStream}
            {...otherAttrs}
        />
    );

    let inputElement = (
        <input
            className={classList('FormControl', className)}
            id={inputId}
            type='text'
            onchange={withAttr('value', inputStream)}
            value={inputStream()}
            {...otherAttrs}
        />
    );

    return (
        <div className="Form-group">
            {label && <label for={selectId}>{label}</label>}
            <div id={helpTextId} className="helpText">{help}</div>
            {selectElement}
            {inputDescription && <label style={{'padding-top': '10px'}} for={inputId}>{inputDescription}</label>}
            {inputElement}
        </div>
    );
}
