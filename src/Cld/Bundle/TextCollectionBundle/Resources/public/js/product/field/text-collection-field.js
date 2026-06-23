'use strict';
/**
 * Text collection product field: edits an ordered list of free-text strings.
 *
 * Value data shape: ["value1", "value2", ...]
 */
define(
    [
        'pim/field',
        'underscore',
        'jquery',
        'cldtextcollection/templates/product/field/text-collection',
        'oro/mediator'
    ],
    function (Field, _, $, fieldTemplate, mediator) {
        return Field.extend({
            fieldTemplate: _.template(fieldTemplate),

            events: {
                'click .text-collection-add-btn': 'addItem',
                'click .text-collection-item-remove': 'removeItem',
                'change .text-collection-item-input': 'updateModel',
                'keyup .text-collection-item-input': 'updateModel'
            },

            renderInput: function (templateContext) {
                return this.fieldTemplate({
                    items: this.normalizeData(templateContext.value && templateContext.value.data),
                    editMode: templateContext.editMode
                });
            },

            /**
             * @param {*} data
             * @returns {Array}
             */
            normalizeData: function (data) {
                if (!_.isArray(data)) {
                    return [];
                }

                return data;
            },

            addItem: function () {
                var data = this.normalizeData(this.getCurrentValue().data);
                data.push('');
                this.setCurrentValue(data);
                this.render();
                mediator.trigger('pim_enrich:form:entity:update_state');
            },

            removeItem: function (event) {
                var index = $(event.currentTarget).data('index');
                var data = this.normalizeData(this.getCurrentValue().data);
                data.splice(index, 1);
                this.setCurrentValue(data);
                this.render();
                mediator.trigger('pim_enrich:form:entity:update_state');
            },

            updateModel: function () {
                var data = [];
                this.$('.text-collection-item-input').each(function () {
                    var val = $(this).val();
                    if (val !== '') {
                        data.push(val);
                    }
                });
                this.setCurrentValue(data);
                mediator.trigger('pim_enrich:form:entity:update_state');
            }
        });
    }
);
