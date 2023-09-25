import Mithril from 'mithril';
import classList from 'flarum/common/utils/classList';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Select from 'flarum/common/components/Select';
import Checkbox, { ICheckboxAttrs } from 'flarum/common/components/Checkbox';

type visibility = 'visible' | 'hidden';

interface ISelectAttrs extends ComponentAttrs {
    options: { [k: string]: Mithril.Children };
    onchange: (value: string) => void;
    value?: string;
    disabled?: boolean;
    wrapperAttrs?: ComponentAttrs;
}

interface ISubSelectAttrs extends ComponentAttrs {
    wrapperAttrs?: ComponentAttrs;
}

export interface ISelectOrInputAttrs<
    SelectAttrs extends ISubSelectAttrs = ISubSelectAttrs
> extends ComponentAttrs {
    onchange: (value: string) => void;
    options: { [k: string]: Mithril.Children };
    value?: string;
    disabled?: boolean;
    checkboxDescription?: string;
    selectAttrs?: SelectAttrs;
    rawTextInputAttrs?: ComponentAttrs;
}

// when value is `undefined`, choose the first item of select
// when value is in select, use value as value of select
// otherwise, use value as value of input
export default class SelectOrInput<CustomAttrs extends ISelectOrInputAttrs> extends Component<CustomAttrs> {    
    private onCheckboxChange(checked: boolean, component: Checkbox) {
        this.attrs.value = checked ? '' : undefined;
        this.attrs.onchange(this.attrs.value!);
        m.redraw();
    }
    private onSelectChange(value: string) {
        this.attrs.value = value;
        this.attrs.onchange(value);
    }
    private onInputChange(event: Event) {
        const value = (event.target as HTMLInputElement).value;
        this.attrs.value = value;
        this.attrs.onchange(value);
    }
    
    view(vnode: Mithril.Vnode<CustomAttrs, this>) {
        const {
            class: _class,
            className,
            onchange,
            options,
            value: _value,
            disabled,
            selectAttrs,
            rawTextInputAttrs,
            ...domAttrs
        } = this.attrs;

        var useinput: boolean; var value: string;
        const selectOptionValues = Object.keys(options);

        if (typeof _value === 'undefined') {
            value = selectOptionValues[0];
            this.attrs.value = value;
            useinput = false;
        } else {
            value = _value;
            useinput = !(selectOptionValues.indexOf(value!) > -1);
        }

        const {
            class: inputClass,
            className: inputClassName,
            ...otherInputAttrs
        } = rawTextInputAttrs ?? {};

        return (
            <div
                className={classList('SelectOrInput', className, _class)}
                {...domAttrs}
            >
                {this.attrs.checkboxDescription ?? ''}
                <div style='display: inline-block;'>
                    <Checkbox
                        state={useinput}
                        onchange={this.onCheckboxChange.bind(this)}
                    />
                </div>
                <div style='display: inline-block;'>
                    {!useinput
                        ? <Select
                            options={options}
                            onchange={this.onSelectChange.bind(this)}
                            disabled={disabled}
                            value={value}
                            {...selectAttrs}
                        />
                        : <input
                            className={classList('FormControl', inputClassName, inputClass)}
                            type='text'
                            onchange={this.onInputChange.bind(this)}
                            disabled={disabled}
                            value={value}
                            {...otherInputAttrs}
                        />
                    }
                </div>
            </div>
        );
    }
}