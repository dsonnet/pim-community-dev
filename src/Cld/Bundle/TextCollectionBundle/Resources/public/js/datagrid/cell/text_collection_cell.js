/* global define */
define(
    [
        'underscore',
        'oro/datagrid/string-cell',
        'cldtextcollection/templates/datagrid/cell/text-collection'
    ],
    function (_, StringCell, template) {
        'use strict';

        return StringCell.extend({
            template: _.template(template),

            render() {
                const collection = this.formatter.fromRaw(this.model.get(this.column.get('name')));
                this.$el.empty().html(this.template({collection: _.isArray(collection) ? collection : []}));

                return this;
            }
        });
    }
);
